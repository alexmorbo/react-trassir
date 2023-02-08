<?php

namespace AlexMorbo\React\Trassir\Controller;

use React\Router\Http\Router;

abstract class AbstractController
{
    protected $middlewares = [
        'before' => [],
        'after' => [],
    ];

    public const BEFORE_MIDDLEWARE = 'before';
    public const AFTER_MIDDLEWARE = 'after';

    public function hasMiddlewares(): bool
    {
        return !empty($this->middlewares['before']) || !empty($this->middlewares['after']);
    }

    public function getMiddlewares(string $type): array
    {
        return $this->middlewares[$type] ?? [];
    }

    public function addRoutes(Router $router): void
    {
    }
}