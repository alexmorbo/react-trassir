<?php

namespace AlexMorbo\React\Trassir\Controller;

use Fig\Http\Message\StatusCodeInterface;
use HttpSoft\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use React\Http\Message\Response;
use Symfony\Component\Yaml\Yaml;
use Tnapf\Router\Router;

class InfoController extends AbstractController
{
    private array $openapi;

    public function __construct(private Yaml $yaml)
    {
        $this->openapi = $yaml->parseFile(__DIR__ . '/../../api.yml');
    }

    public function addRoutes(Router $router): void
    {
        $router->get("/api/version", fn() => $this->version());
        $router->get("/api/openapi.yml", fn() => $this->yml());
        $router->get("/api/openapi.json", fn() => $this->json());
    }

    public function version(): ResponseInterface
    {
        return new Response(
            StatusCodeInterface::STATUS_OK,
            [
                'Content-Type' => 'text/plain'
            ],
            $this->openapi['info']['version']
        );
    }

    public function yml(): ResponseInterface
    {
        return new Response(
            StatusCodeInterface::STATUS_OK,
            [
                'Content-Type' => 'application/x-yaml'
            ],
            $this->yaml->dump($this->openapi, 10, 2)
        );
    }

    public function json(): ResponseInterface
    {
        return new JsonResponse($this->openapi);
    }
}