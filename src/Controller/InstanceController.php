<?php

namespace AlexMorbo\React\Trassir\Controller;

use AlexMorbo\React\Trassir\Dto\Instance;
use AlexMorbo\React\Trassir\Log;
use AlexMorbo\React\Trassir\Traits\DBTrait;
use AlexMorbo\React\Trassir\TrassirHelper;
use AlexMorbo\Trassir\TrassirException;
use Clue\React\SQLite\DatabaseInterface;
use Exception;
use Fig\Http\Message\StatusCodeInterface;
use HttpSoft\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\Http\Message\Response;
use Tnapf\Router\Handlers\ClosureRequestHandler;
use Tnapf\Router\Router;
use Tnapf\Router\Routing\RouteRunner;

use function React\Async\await;
use function React\Promise\all;
use function React\Promise\resolve;

class InstanceController extends AbstractController
{
    use DBTrait;

    public function __construct(
        private LoggerInterface $logger,
        DatabaseInterface $db,
        protected TrassirHelper $trassirHelper
    ) {
        $this->initDB($db);
    }

    public function addRoutes(Router $router): void
    {
        $router->get("/api/instances", ClosureRequestHandler::new(fn() => $this->getInstances()));
        $router->post(
            "/api/instances",
            ClosureRequestHandler::new(
                fn(ServerRequestInterface $request, ResponseInterface $response) => $this->addInstance(
                    $request,
                    $response
                )
            )
        );
        $router
            ->delete(
                "/api/instance/{instanceId}",
                ClosureRequestHandler::new(
                    fn(
                        ServerRequestInterface $request,
                        ResponseInterface $response,
                        RouteRunner $runner
                    ) => $this->deleteInstance($runner->getParameter('instanceId'))
                )
            )
            ->setParameter('instanceId', '\d+');
        $router
            ->get(
                "/api/instance/{instanceId}",
                ClosureRequestHandler::new(
                    fn(
                        ServerRequestInterface $request,
                        ResponseInterface $response,
                        RouteRunner $runner
                    ) => $this->getInstance($runner->getParameter('instanceId'))
                )
            )
            ->setParameter('instanceId', '\d+');
        $router
            ->get(
                "/api/instance/{instanceId}/channel/{channelId}/screenshot",
                ClosureRequestHandler::new(
                    fn(
                        ServerRequestInterface $request,
                        ResponseInterface $response,
                        RouteRunner $runner
                    ) => $this->getChannelScreenshot(
                        $response,
                        $runner->getParameter('instanceId'),
                        $runner->getParameter('channelId'),
                    )
                )
            )
            ->setParameter('instanceId', '\d+');
        $router
            ->get(
                "/api/instance/{instanceId}/channel/{channelId}/video/{streamType}",
                ClosureRequestHandler::new(
                    fn(
                        ServerRequestInterface $request,
                        ResponseInterface $response,
                        RouteRunner $runner
                    ) => $this->getChannelVideo(
                        $request,
                        $response,
                        $runner->getParameter('instanceId'),
                        $runner->getParameter('channelId'),
                        $runner->getParameter('streamType'),
                    )
                )
            )
            ->setParameter('instanceId', '\d+');
    }

    public function getInstances(): ResponseInterface
    {
        $promise = $this
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

        return await($promise);
    }

    public function getInstance(
        string $instanceId
    ): ResponseInterface {
        $instanceId = (int)$instanceId;

        $promise = $this->dbSearch('instances', ['id' => $instanceId])
            ->then(
                function ($result) use ($instanceId) {
                    if (!$result) {
                        return new JsonResponse(['status' => 'error', 'error' => 'Instance not found'], 404);
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

        return await($promise);
    }

    public function addInstance(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (
            $request->hasHeader('Content-Type') &&
            $request->getHeaderLine('Content-Type') === 'application/json'
        ) {
            $input = json_decode($request->getBody()->getContents(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['status' => 'error', 'error' => 'Invalid JSON'], 400);
            }
        }

        if (
            empty($input['ip']) ||
            empty($input['http_port']) ||
            empty($input['rtsp_port']) ||
            empty($input['login']) ||
            empty($input['password'])
        ) {
            return new JsonResponse(['status' => 'error', 'error' => 'Invalid data'], 400);
        }

        $instanceId = $this->dbInsert('instances', [
            'ip' => $input['ip'],
            'http_port' => $input['http_port'],
            'rtsp_port' => $input['rtsp_port'],
            'login' => $input['login'],
            'password' => $input['password'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $promise = $instanceId
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
                                                    fn() => new JsonResponse(
                                                        ['status' => 'success', 'id' => $instanceId]
                                                    )
                                                );
                                        }
                                    )
                                    ->catch(
                                        fn(Exception $e) => new JsonResponse([
                                            'status' => 'error',
                                            'error' => $e->getMessage()
                                        ], StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR)
                                    );
                            }
                        );
                },
                function ($error) {
                    return new JsonResponse(['status' => 'error', 'error' => $error]);
                }
            );

        return await($promise);
    }

    public function deleteInstance(
        $instanceId
    ): ResponseInterface {
        $instanceId = (int)$instanceId;

        $promise = $this
            ->dbDelete('instances', [
                'id' => $instanceId,
            ])
            ->then(
                function (int $deletedRows) use ($instanceId) {
                    if ($deletedRows === 0) {
                        $this->logger->warning('Instance not deleted', ['instanceId' => $instanceId]);

                        return new JsonResponse(
                            ['status' => 'error', 'error' => 'Instance not found'],
                            StatusCodeInterface::STATUS_NOT_FOUND
                        );
                    }

                    $this->logger->info('Instance deleted', ['instanceId' => $instanceId]);

                    return $this->trassirHelper
                        ->pull()
                        ->then(fn() => new Response(StatusCodeInterface::STATUS_NO_CONTENT));
                },
                function ($error) {
                    return new JsonResponse(['status' => 'error', 'error' => $error]);
                }
            );

        return await($promise);
    }

    public function getChannelScreenshot(
        ResponseInterface $response,
        string $instanceId,
        string $channelId
    ): ResponseInterface {
        $instanceId = (int)$instanceId;

        $promise = $this->trassirHelper->getInstance($instanceId)
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

        return await($promise);
    }

    public function getChannelVideo(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $instanceId,
        string $channelId,
        string $streamType,
    ): ResponseInterface {
        $instanceId = (int)$instanceId;

        if (!in_array($streamType, ['hls', 'rtsp'])) {
            return resolve(new JsonResponse(['status' => 'error', 'error' => 'Invalid stream type'], 400));
        }

        $promise = $this->trassirHelper->getInstance($instanceId)
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
                function (Exception $e) {
                    return new JsonResponse(['status' => 'error', 'error' => $e->getMessage()], 404);
                }
            );

        return await($promise);
    }


}