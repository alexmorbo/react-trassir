<?php

namespace AlexMorbo\React\Trassir\Controller;

use Fig\Http\Message\StatusCodeInterface;
use HttpSoft\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;
use React\Router\Http\Router;
use Symfony\Component\Yaml\Yaml;

class InfoController extends AbstractController
{
    private array $openapi;

    public function __construct(private Yaml $yaml)
    {
        $this->openapi = $yaml->parseFile(__DIR__ . '/../../api.yml');
    }

    public function addRoutes(Router $router): void
    {
        $router
            ->get("/api/version", [$this, 'version'])
            ->get("/api/openapi.yml", [$this, 'yml'])
            ->get("/api/openapi.json", [$this, 'json']);
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