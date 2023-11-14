<?php

namespace AlexMorbo\React\Trassir\Controller;

use AlexMorbo\React\Trassir\Dto\Instance;
use AlexMorbo\React\Trassir\Router\Router;
use AlexMorbo\React\Trassir\Traits\DBTrait;
use AlexMorbo\React\Trassir\TrassirHelper;
use AlexMorbo\Trassir\TrassirException;
use Clue\React\SQLite\DatabaseInterface;
use Exception;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function React\Promise\all;
use function React\Promise\resolve;

class InstanceController extends AbstractController
{
    use DBTrait;

    public function __construct(
        protected ?LoggerInterface $logger,
        DatabaseInterface $db,
        protected TrassirHelper $trassirHelper
    ) {
        $this->initDB($db);
    }

    public function addRoutes(Router $router): void
    {
        $router->get("/api/instances", fn() => $this->getInstances());
        $router->post(
            "/api/instances",
            fn(ServerRequestInterface $request) => $this->addInstance($request)
        );
        $router->delete(
            "/api/instance/{instanceId}",
            fn(ServerRequestInterface $request, $instanceId) => $this->deleteInstance($instanceId)
        );
        $router->get(
            "/api/instance/{instanceId}",
            fn(ServerRequestInterface $request, $instanceId) => $this->getInstance($instanceId)
        );
        $router->get(
            "/api/instance/{instanceId}/channel/{channelId}/screenshot",
            fn(ServerRequestInterface $request, $instanceId, $channelId) => $this->getChannelScreenshot(
                $instanceId,
                $channelId
            )
        );
        $router->get(
            "/api/instance/{instanceId}/channel/{channelId}/video/{streamType}",
            fn(ServerRequestInterface $request, $instanceId, $channelId, $streamType) => $this->getChannelVideo(
                $request,
                $instanceId,
                $channelId,
                $streamType
            )
        );
    }

    public function getInstances(): PromiseInterface
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
                        ->then(fn($instancesData) => Response::json($instancesData));
                }
            );
    }

    public function getInstance(string $instanceId): PromiseInterface
    {
        $instanceId = (int)$instanceId;

        return $this->dbSearch('instances', ['id' => $instanceId])
            ->then(
                function ($result) use ($instanceId) {
                    if (!$result) {
                        throw new NotFoundHttpException('Instance Not Found');
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
                        ->then(fn($instanceData) => Response::json($instanceData));
                }
            );
    }

    public function addInstance(ServerRequestInterface $request): PromiseInterface
    {
        if (
            $request->hasHeader('Content-Type') &&
            $request->getHeaderLine('Content-Type') === 'application/json'
        ) {
            $input = json_decode($request->getBody()->getContents(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new BadRequestHttpException('Invalid JSON');
            }
        }

        if (
            empty($input['ip']) ||
            empty($input['http_port']) ||
            empty($input['rtsp_port']) ||
            empty($input['login']) ||
            empty($input['password'])
        ) {
            throw new BadRequestHttpException('Invalid JSON Data');
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
                                        function () use ($instance, $instanceId) {
                                            $settings = $instance->getTrassir()->getSettings();
                                            $this->dbUpdate(
                                                'instances',
                                                ['name' => $settings['name']],
                                                ['id' => $instanceId]
                                            );

                                            $this->logger->info('Instance created', ['instanceId' => $instanceId]);

                                            return $this->trassirHelper
                                                ->pull()
                                                ->then(
                                                    fn() => Response::json(
                                                        ['status' => 'success', 'id' => $instanceId]
                                                    )
                                                );
                                        }
                                    );
                            }
                        );
                },
            );
    }

    public function deleteInstance($instanceId): PromiseInterface
    {
        $instanceId = (int)$instanceId;

        return $this
            ->dbDelete('instances', [
                'id' => $instanceId,
            ])
            ->then(
                function (int $deletedRows) use ($instanceId) {
                    if ($deletedRows === 0) {
                        $this->logger->warning('Instance not deleted', ['instanceId' => $instanceId]);

                        throw new NotFoundHttpException('Instance Not Found');
                    }

                    $this->logger->info('Instance deleted', ['instanceId' => $instanceId]);

                    return $this->trassirHelper
                        ->pull()
                        ->then(fn() => resolve(
                            new Response(StatusCodeInterface::STATUS_NO_CONTENT)
                        ));
                }
            );
    }

    public function getChannelScreenshot(string $instanceId, string $channelId): PromiseInterface
    {
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

                    throw new NotFoundHttpException('Channel Not Found');
                }
            )
            ->then(
                function ($screenshot) {
                    return Response::plaintext($screenshot);
                }
            );
    }

    public function getChannelVideo(
        ServerRequestInterface $request,
        string $instanceId,
        string $channelId,
        string $streamType,
    ): PromiseInterface {
        $instanceId = (int)$instanceId;

        if (!in_array($streamType, ['hls', 'rtsp'])) {
            throw new BadRequestHttpException('Invalid stream type');
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

                    throw new NotFoundHttpException('Channel Not Found');
                }
            )
            ->then(
                function ($video) use ($request) {
                    $useRedirect = $request->getQueryParams()['redirect'] ?? false;
                    if ($useRedirect) {
                        $response = Response::plaintext('')
                            ->withAddedHeader('Location', $video)
                            ->withStatus(StatusCodeInterface::STATUS_FOUND);
                    } else {
                        $response = Response::plaintext($video);
                    }

                    return $response;
                }
            );
    }


}