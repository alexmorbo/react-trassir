<?php

namespace AlexMorbo\React\Trassir\Controller;

use Fig\Http\Message\StatusCodeInterface;
use React\Http\Message\Response;
use React\Router\Http\Router;

class InfoController extends AbstractController
{
    public function addRoutes(Router $router): void
    {
        $router
            ->get("/api.yml", [$this, 'yml']);
    }

    public function yml(): Response
    {
        $yml = file_get_contents(__DIR__ . '/../../api.yml');

        return new Response(
            StatusCodeInterface::STATUS_OK,
            [
                'Content-Type' => 'application/x-yaml'
            ],
            $yml
        );
    }
}