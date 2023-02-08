<?php

namespace AlexMorbo\React\Trassir\Controller;

use AlexMorbo\React\Trassir\Dto\Instance;
use AlexMorbo\React\Trassir\Traits\DBTrait;
use AlexMorbo\React\Trassir\TrassirHelper;
use AlexMorbo\Trassir\TrassirException;
use Clue\React\SQLite\DatabaseInterface;
use Fig\Http\Message\StatusCodeInterface;
use HttpSoft\Response\JsonResponse;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use React\Promise\PromiseInterface;
use React\Router\Http\Router;

use function React\Promise\all;
use function React\Promise\resolve;

class InstanceController extends AbstractController
{
    use DBTrait;

    public function __construct(DatabaseInterface $db, private TrassirHelper $trassirHelper)
    {
        $this->initDB($db);
    }

    public function addRoutes(Router $router): void
    {
        $router
            ->get("/instances", [$this, 'getInstances'])
            ->post("/instances", [$this, 'addInstance'])
            ->delete("/instance/(\d+)", [$this, 'deleteInstance'])
            ->get("/instance/(\d+)", [$this, 'getInstance'])
            ->get("/instance/(\d+)/channel/(.*)/screenshot", [$this, 'getChannelScreenshot'])
            ->get("/instance/(\d+)/channel/(.*)/video/(.*)", [$this, 'getChannelVideo']);
    }

    public function getInstances()
    {
        return $this
            ->dbSearch('instances')
            ->then(
                function ($result) {
                    $promises = [];
                    foreach ($result as $instanceData) {
                        $promises[] = $this->trassirHelper->getInstance($instanceData['id'])
                            ->then(
                                function (Instance $instance) use ($instanceData) {
                                    return array_merge(
                                        $instanceData,
                                        ['state' => $instance->getTrassir()->getState()],
                                        $instance->getTrassir()->getChannels()
                                    );
                                }
                            );
                    }

                    return all($promises)
                        ->then(fn($instancesData) => new JsonResponse($instancesData));
                }
            );
    }

    public function getInstance(ServerRequest $request, Response $response, string $instanceId)
    {
        $instanceId = (int)$instanceId;
        return $this->dbSearch('instances', ['id' => $instanceId])
            ->then(
                function ($result) use ($instanceId) {
                    if (!$result) {
                        return new JsonResponse(['error' => 'Instance not found'], 404);
                    }

                    return $this->trassirHelper->getInstance($instanceId)
                        ->then(
                            function (Instance $instance) use ($result) {
                                return array_merge(
                                    $result[0],
                                    ['state' => $instance->getTrassir()->getState()],
                                    $instance->getTrassir()->getChannels()
                                );
                            }
                        )
                        ->then(fn($instanceData) => new JsonResponse($instanceData));
                }
            );
    }

    public function addInstance(ServerRequest $request, Response $response): PromiseInterface
    {
        if (
            $request->hasHeader('Content-Type') &&
            $request->getHeaderLine('Content-Type') === 'application/json'
        ) {
            $input = json_decode($request->getBody()->getContents(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return resolve(new JsonResponse(['error' => 'Invalid JSON'], 400));
            }
        }

        if (
            empty($input['ip']) ||
            empty($input['http_port']) ||
            empty($input['rtsp_port']) ||
            empty($input['login']) ||
            empty($input['password'])
        ) {
            return resolve(new JsonResponse(['error' => 'Invalid data'], 400));
        }

        $instanceId = $this->dbInsert('instances', [
            'ip' => $input['ip'],
            'http_port' => $input['http_port'],
            'rtsp_port' => $input['rtsp_port'],
            'login' => $input['login'],
            'password' => $input['password'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $instanceId
            ->then(
                function ($instanceId) {
                    return $this->trassirHelper
                        ->connectByInstanceId($instanceId)
                        ->then(
                            function (Instance $instance) use ($instanceId) {
                                return $instance->getTrassir()->getConnection()
                                    ->then(
                                        function() use ($instance, $instanceId) {
                                            $settings = $instance->getTrassir()->getSettings();
                                            $this->dbUpdate(
                                                'instances',
                                                ['name' => $settings['name']],
                                                ['id' => $instanceId]
                                            );

                                            return new JsonResponse(['status' => 'success', 'id' => $instanceId]);
                                        }
                                    )
                                    ->otherwise(
                                        fn(TrassirException $e) => new JsonResponse([
                                            'status' => 'error',
                                            'error' => $e->getMessage()
                                        ], StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR)
                                    );
                            }
                        );
                },
                function ($error) {
                    return new JsonResponse(['error' => $error]);
                }
            );
    }

    public function deleteInstance(ServerRequest $request, Response $response, $input): PromiseInterface
    {
        return $this
            ->dbDelete('instances', [
                'id' => $input,
            ])
            ->then(
                function (int $deletedRows) {
                    if ($deletedRows === 0) {
                        return new JsonResponse(
                            ['error' => 'Instance not found'], StatusCodeInterface::STATUS_NOT_FOUND
                        );
                    }

                    return new Response(StatusCodeInterface::STATUS_NO_CONTENT);
                },
                function ($error) {
                    return new JsonResponse(['error' => $error]);
                }
            );
    }

    public function getChannelScreenshot(
        ServerRequest $request,
        Response $response,
        string $instanceId,
        string $channelId
    ) {
        $instanceId = (int)$instanceId;
        return $this->trassirHelper->getInstance($instanceId)
            ->then(
                function (Instance $instance) use ($channelId) {
                    foreach ($instance->getTrassir()->getChannels() as $type => $channels) {
                        foreach ($channels as $channel) {
                            if ($channel['guid'] === $channelId) {
                                if ($type === 'channels') {
                                    return $instance->getTrassir()->getScreenshot($instance->getName(), $channelId);
                                } else {
                                    return $instance->getTrassir()->getScreenshot($channel['server_guid'], $channelId);
                                }
                            }
                        }
                    }
                }
            )
            ->then(
                function ($screenshot) use ($response) {
                    $response->getBody()->write($screenshot);
                    return $response;
                }
            );
    }

    public function getChannelVideo(
        ServerRequest $request,
        Response $response,
        string $instanceId,
        string $channelId,
        string $streamType,
    ) {
        $instanceId = (int)$instanceId;

        if (!in_array($streamType, ['hls', 'rtsp'])) {
            return resolve(new JsonResponse(['error' => 'Invalid stream type'], 400));
        }

        return $this->trassirHelper->getInstance($instanceId)
            ->then(
                function (Instance $instance) use ($channelId, $streamType) {
                    foreach ($instance->getTrassir()->getChannels() as $type => $channels) {
                        foreach ($channels as $channel) {
                            if ($channel['guid'] === $channelId) {
                                if ($type === 'channels') {
                                    return $instance->getTrassir()->getVideo(
                                        $instance->getName(),
                                        $channelId,
                                        $streamType
                                    );
                                } else {
                                    return $instance->getTrassir()->getVideo(
                                        $channel['server_guid'],
                                        $channelId,
                                        $streamType
                                    );
                                }
                            }
                        }
                    }

                    throw new TrassirException('Channel not found');
                }
            )
            ->then(
                function ($video) use ($request, $response) {
                    $useRedirect = $request->getQueryParams()['redirect'] ?? false;
                    if ($useRedirect) {
                        $response = $response
                            ->withAddedHeader('Location', $video)
                            ->withStatus(StatusCodeInterface::STATUS_FOUND);
                    } else {
                        $response->getBody()->write($video);
                    }

                    return $response;
                },
                function (TrassirException $e) {
                    return new JsonResponse(['error' => $e->getMessage()], 404);
                }
            );
    }


}