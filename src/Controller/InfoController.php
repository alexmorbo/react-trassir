<?php

namespace AlexMorbo\React\Trassir\Controller;

use AlexMorbo\React\Trassir\Router\Router;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use Symfony\Component\Yaml\Yaml;

use function React\Promise\resolve;

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

    public function version(): PromiseInterface
    {
        return resolve(
            Response::plaintext($this->openapi['info']['version'])
        );
    }

    public function yml(): PromiseInterface
    {
        return resolve(
            new Response(
                StatusCodeInterface::STATUS_OK,
                [
                    'Content-Type' => 'application/x-yaml'
                ],
                $this->yaml->dump($this->openapi, 10, 2)
            )
        );
    }

    public function json(): PromiseInterface
    {
        return resolve(Response::json($this->openapi));
    }
}