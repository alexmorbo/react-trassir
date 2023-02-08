<?php

namespace AlexMorbo\React\Trassir;

use AlexMorbo\React\Trassir\Dto\Instance;
use AlexMorbo\React\Trassir\Traits\DBTrait;
use AlexMorbo\Trassir\ConnectionOptions;
use AlexMorbo\Trassir\Dto\Server;
use AlexMorbo\Trassir\Trassir;
use Clue\React\SQLite\DatabaseInterface;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

class TrassirHelper
{
    use DBTrait;

    private array $instances = [];

    public function __construct(DatabaseInterface $db)
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
                'host' => $instanceData['ip'],
                'httpPort' => $instanceData['http_port'],
                'rtspPort' => $instanceData['rtsp_port'],
                'login' => $instanceData['login'],
                'password' => $instanceData['password'],
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
            )
            ->then(
                function (array $instances) {
                    /**
                     * Update instance id in database
                     */
                    foreach ($instances as $instanceId) {
                        $settings = $this->instances[$instanceId]->getTrassir()->getSettings();
                        $this->dbUpdate(
                            'instances',
                            ['name' => $settings['name']],
                            ['id' => $instanceId]
                        );
                    }
                }
            );
    }
}