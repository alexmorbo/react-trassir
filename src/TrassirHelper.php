<?php

namespace AlexMorbo\React\Trassir;

use AlexMorbo\React\Trassir\Dto\Instance;
use AlexMorbo\React\Trassir\Traits\DBTrait;
use AlexMorbo\Trassir\ConnectionOptions;
use AlexMorbo\Trassir\Dto\Server;
use AlexMorbo\Trassir\Trassir;
use AlexMorbo\Trassir\TrassirException;
use Clue\React\SQLite\DatabaseInterface;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

class TrassirHelper
{
    use DBTrait;

    private array $instances = [];

    public function __construct(DatabaseInterface $db, private LoggerInterface $logger)
    {
        $this->initDB($db);
    }

    public function getInstance(int $id): PromiseInterface
    {
        return isset($this->instances[$id])
            ? resolve($this->instances[$id])
            : $this->connectByInstanceId($id);
    }

    public function connectByInstanceId(int $instanceId): PromiseInterface
    {
        return $this->dbSearch('instances', ['id' => $instanceId])
            ->then(
                function ($result) {
                    if (empty($result)) {
                        throw new TrassirException('Instance not found');
                    }

                    $this->instances[$result[0]['id']] = new Instance(
                        $result[0], $this->getTrassirInstance($result[0])
                    );

                    return $this->instances[$result[0]['id']];
                }
            );
    }

    public function getTrassirInstance(array $instanceData): Trassir
    {
        $options = ConnectionOptions::fromServer(
            Server::fromArray([
                'id' => $instanceData['id'],
                'host' => $instanceData['ip'],
                'httpPort' => $instanceData['http_port'],
                'rtspPort' => $instanceData['rtsp_port'],
                'login' => $instanceData['login'],
                'password' => $instanceData['password'],
                'proxy' => getenv('PROXY'),
                'logger' => $this->logger,
            ])
        );

        return Trassir::getInstance($options);
    }

    private function connect(array $instanceData): PromiseInterface
    {
        return $this->getTrassirInstance($instanceData)->getConnection();
    }

    public function pull(): PromiseInterface
    {
        return $this
            ->dbSearch('instances')
            ->then(
                function ($result) {
                    $promises = [];
                    foreach ($result as $instanceData) {
                        $this->instances[$instanceData['id']] = new Instance(
                            $instanceData, $this->getTrassirInstance($instanceData)
                        );

                        $promises[] = $this->connect($instanceData)->then(fn() => $instanceData['id']);
                    }

                    return all($promises);
                }
            );
    }
}