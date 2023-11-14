<?php

namespace AlexMorbo\React\Trassir\Controller;

use AlexMorbo\React\Trassir\Router\Router;
use HttpSoft\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use Throwable;

use function React\Async\await;

abstract class AbstractController
{
    protected ?LoggerInterface $logger = null;

    public function addRoutes(Router $router): void
    {
    }

    protected function awaitResponse(PromiseInterface $promise): ResponseInterface
    {
        try {
            return await($promise);
        } catch (Throwable $e) {
            $this->logger->error('Async error', ['message' => $e->getMessage()]);

            return new JsonResponse(['status' => 'error', 'error' => $e->getMessage()], 404);
        }
    }
}