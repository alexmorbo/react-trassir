<?php

namespace AlexMorbo\React\Trassir;

use AlexMorbo\React\Trassir\Controller\AbstractController;
use AlexMorbo\React\Trassir\Controller\InfoController;
use AlexMorbo\React\Trassir\Controller\InstanceController;
use AlexMorbo\React\Trassir\Router\Router;
use AlexMorbo\Trassir\TrassirException;
use Clue\React\SQLite\DatabaseInterface;
use Clue\React\SQLite\Factory;
use Exception;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Yaml\Yaml;

class Server extends Command
{
    protected static $defaultName = 'server:run';
    private string $dbPath;

    private LoopInterface $loop;
    private TrassirHelper $trassirHelper;
    private DatabaseInterface $db;
    private Logger $logger;

    public function __construct()
    {
        $this->dbPath = __DIR__ . '/../data/data.db';
        $this->logger = new Logger('react-trassir');
        $handler = new StreamHandler('php://stdout', getenv('LOG_LEVEL') ?: Level::Debug);
        $handler->setFormatter(new JsonFormatter());
        $this->logger->pushHandler($handler);

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('ip', null, InputOption::VALUE_OPTIONAL, 'Listen ip')
            ->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'Listen port');
    }

    private function initDataBase(): PromiseInterface
    {
        $factory = new Factory();
        return $factory
            ->open($this->dbPath)
            ->then(fn(DatabaseInterface $db) => $this->db = $db)
            ->then(fn() => $this->migrate())
            ->then(
                function () {
                    $this->trassirHelper = new TrassirHelper($this->db, $this->logger);
                    $this->trassirHelper->pull();
                }
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->loop = Loop::get();
        $io = new SymfonyStyle($input, $output);

        if (!($ip = $input->getOption('ip'))) {
            $ip = $io->ask('Enter listen ip');
        }
        if (!($port = $input->getOption('port'))) {
            $port = (int)$io->ask('Enter listen port');
        }

        $this
            ->initDataBase()
            ->then(fn() => $this->initServer($ip, $port));

        return Command::SUCCESS;
    }

    private function getControllers(): array
    {
        return [
            new InfoController(new Yaml()),
            new InstanceController(
                $this->logger,
                $this->db,
                $this->trassirHelper
            ),
        ];
    }

    private function migrate(): PromiseInterface
    {
        return $this->db->query(
            'CREATE TABLE IF NOT EXISTS `instances` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `ip` TEXT NOT NULL,
            `name` TEXT NULL,
            `http_port` INTEGER NOT NULL,
            `rtsp_port` INTEGER NOT NULL,
            `login` TEXT NOT NULL,
            `password` TEXT NOT NULL,
            `created_at` TEXT NOT NULL
        )'
        );
    }

    private function initServer(string $ip, int $port): int
    {
        $socket = new SocketServer(sprintf("%s:%s", $ip, $port), [], $this->loop);
        $router = $this->getRouter();

        $http = new HttpServer(
            function (ServerRequestInterface $request) use ($router) {
                $id = uniqid();
                $this->logger->debug('New request', [
                    'id' => $id,
                    'uri' => $request->getUri(),
                    'method' => $request->getMethod(),
                    'remote_addr' => $request->getServerParams()['REMOTE_ADDR']
                ]);

                return $router
                    ->handle($request)
                    ->then(function (ResponseInterface $response) use ($id) {
                        $body = $response->getBody()->getContents();

                        $this->logger->debug('Response', [
                            'id' => $id,
                            'headers' => $response->getHeaders(),
                            'body' => strlen($body) > 200 ? '..' : $body,
                        ]);

                        return $response;
                    })
                    ->catch(function (NotFoundHttpException $e) {
                        return Response::json([
                            'status' => 'error',
                            'error' => $e->getMessage(),
                        ]);
                    })
                    ->catch(function (TrassirException|Exception $e) {
                        $this->logger->error('Caught an exception', [
                            'exception' => get_class($e),
                            'message' => $e->getMessage(),
                            'code' => $e->getCode(),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        return Response::json([
                            'status' => 'error',
                            'error' => $e->getMessage(),
                        ]);
                    });
            }
        );
        $http->on('error', function (Exception $e) {
            $this->logger->error($e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            $this->loop->stop();
        });
        $http->listen($socket);

        $this->logger->info(
            'Listening on ' . str_replace('tcp:', 'http:', $socket->getAddress())
        );

        $this->loop->addPeriodicTimer(300, function () {
            $this->logger->info(
                sprintf(
                    'Current memory usage %f mb, max: %f mb',
                    round(memory_get_usage() / 1024 / 1024, 2) . ' mb',
                    round(memory_get_peak_usage() / 1024 / 1024, 2) . ' mb'
                ),
                [
                    'memory_current' => memory_get_usage(),
                    'memory_max' => memory_get_peak_usage(),
                ]
            );
        });

        return Command::SUCCESS;
    }

    private function getRouter(): Router
    {
        $router = new Router();
        $this->addRoutes($router);

        return $router;
    }

    protected function addRoutes(Router $router): void
    {
        /** @var AbstractController $controller */
        foreach ($this->getControllers() as $controller) {
            $controller->addRoutes($router);
        }
    }
}
