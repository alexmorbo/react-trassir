<?php

namespace AlexMorbo\React\Trassir\Router;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function React\Promise\reject;
use function React\Promise\resolve;

class Router
{
    /**
     *
     * @var array<RouteItem>
     */
    protected array $routes = [];

    public function register(string $method, string $url, $action, ?array $defaults = null, array $wheres = [])
    {
        $item = new RouteItem(
            $method,
            $url,
            $action,
            $defaults,
            $wheres
        );
        $this->routes[] = $item;
        // TODO: adapter
        return $item;
    }

    public function get(string $url, $action, ?array $defaults = null, array $wheres = [])
    {
        return $this->register('get', $url, $action, $defaults, $wheres);
    }

    public function post(string $url, $action, ?array $defaults = null, array $wheres = [])
    {
        return $this->register('post', $url, $action, $defaults, $wheres);
    }

    public function put(string $url, $action, ?array $defaults = null, array $wheres = [])
    {
        return $this->register('put', $url, $action, $defaults, $wheres);
    }

    public function delete(string $url, $action, ?array $defaults = null, array $wheres = [])
    {
        return $this->register('delete', $url, $action, $defaults, $wheres);
    }

    public function patch(string $url, $action, ?array $defaults = null, array $wheres = [])
    {
        return $this->register('patch', $url, $action, $defaults, $wheres);
    }

    public function options(string $url, $action, ?array $defaults = null, array $wheres = [])
    {
        return $this->register('options', $url, $action, $defaults, $wheres);
    }

    public function all(string $url, $action, ?array $defaults = null, array $wheres = [])
    {
        return $this->register('*', $url, $action, $defaults, $wheres);
    }

    /**
     *
     * @param mixed $url
     * @param string $method
     * @return array{item: RouteItem, params: array}
     */
    public function resolve(string $url, string $method = 'get'): ?array
    {
        if (empty($url)) {
            $url = '/';
        }

        $parts = explode('/', trim($url, '/'));
        $method = strtolower($method);
        foreach ($this->routes as $item) {
            if ($method === $item->method || $item->method === '*') {
                $params = [];
                $valid = true;
                $targetUrl = trim($item->url, '/');
                $targetParts = explode('/', $targetUrl);
                $pi = 0;
                $skipAll = false;
                $count = count($targetParts);
                for ($pi; $pi < $count; $pi++) {
                    $urlExpr = $targetParts[$pi];
                    $hasWildCard = strpos($urlExpr, '*') !== false;
                    if ($hasWildCard) {
                        $beginning = substr($urlExpr, 0, -1);
                        if ($beginning === '' || strpos($parts[$pi], $beginning) === 0) {
                            $skipAll = true;
                            break;
                        }
                    }
                    $hasParams = strpos($urlExpr, '{') !== false;
                    if (
                        (!isset($parts[$pi]) || $urlExpr !== $parts[$pi])
                        && !$hasParams
                    ) {
                        $valid = false;
                        break;
                    }
                    if ($hasParams) {
                        // has {***} parameter
                        $bracketParts = preg_split('/[{}]+/', $urlExpr);
                        $paramName = $bracketParts[1];
                        if ($paramName[strlen($paramName) - 1] === '?') {
                            // $optional
                            $paramName = substr($paramName, 0, -1);
                        } else {
                            if ($pi >= count($parts)) {
                                $valid = false;
                                break;
                            }
                        }
                        if (strpos($paramName, '<') !== false) { // has <regex>
                            preg_match('/<([^>]+)>/', $paramName, $matches);
                            $paramName = preg_replace('/<([^>]+)>/', '', $paramName);

                            // print_r($matches);
                            $item->wheres[$paramName] = $matches[1];
                        }
                        if (isset($item->wheres[$paramName])) {
                            $regex = '/' . $item->wheres[$paramName] . '/';
                            if (preg_match($regex, $parts[$pi]) === 0) {
                                $valid = false;
                                break;
                            }
                            // test for "/"
                            if (preg_match($regex, '/') === 1) { // skip to the end
                                $skipAll = true;
                            }
                        }
                        $paramValue = $parts[$pi] ?? null;
                        if ($bracketParts[0]) {
                            if (strpos($paramValue, $bracketParts[0]) !== 0) {
                                $valid = false;
                                break;
                            } else {
                                $paramValue = substr($paramValue, strlen($bracketParts[0]));
                            }
                        }
                        $params[$paramName] = $paramValue;
                        if ($skipAll) {
                            $params[$paramName] = implode('/', array_slice($parts, $pi));
                            break;
                        }
                    }
                }
                if ($pi < count($parts) && !$skipAll) {
                    $valid = false;
                }
                if ($valid) {
                    return [
                        'item' => $item,
                        'params' => $params
                    ];
                }
            }
        }

        return null;
    }

    public function handle(ServerRequestInterface $request): PromiseInterface
    {
        try {
            $match = $this->resolve($request->getUri()->getPath(), $request->getMethod());
            if (!$match) {
                throw new NotFoundHttpException('Route not found');
            }

            $routeItem = $match['item'];
            $action = $routeItem->action;

            if (!is_callable($action)) {
                throw new \Exception('Internal Error #1');
            }

            $response = $action($request, ...array_values($match['params']));
            if (!$response instanceof PromiseInterface) {
                throw new \Exception('Internal Error #2');
            }

            return $response;
        } catch (\Throwable $e) {
            return reject($e);
        }
    }
}