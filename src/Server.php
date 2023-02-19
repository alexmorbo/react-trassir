<?php

namespace AlexMorbo\React\Trassir;

use AlexMorbo\React\Trassir\Controller\AbstractController;
use AlexMorbo\React\Trassir\Controller\InfoController;
use AlexMorbo\React\Trassir\Controller\InstanceController;
use Clue\React\SQLite\DatabaseInterface;
use Clue\React\SQLite\Factory;
use DateTime;
use HttpSoft\Response\JsonResponse;
use HttpSoft\Response\TextResponse;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use React\Router\Http\Router;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function React\Promise\resolve;

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
        $this->logger->pushHandler(
            new StreamHandler('php://stdout', getenv('LOG_LEVEL') ?: Level::Debug)
        );

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
            new InfoController(),
            new InstanceController(
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
        try {
            $socket = new SocketServer(sprintf("%s:%s", $ip, $port), [], $this->loop);
        } catch (Throwable $e) {
            echo $e->getMessage() . PHP_EOL;

            $this->loop->stop();
            return Command::FAILURE;
        }

        $router = new Router($socket);
        $this->addRoutes($router);
        $this->addMiddlewares($router);

        $router
            ->map404("/(.*)", function () {
                return resolve(new JsonResponse(['status' => 'error', 'error' => 'Route not found'], 404));
            })
            ->map500("/(.*)", function () {
                return resolve(new TextResponse("An internal error has occurred", 500));
            });

        $router
            ->getHttpServer()
            ->on('error', fn() => var_dump(func_get_args()));

        $router->listen();

        echo 'Listening on ' . str_replace('tcp:', 'http:', $socket->getAddress()) . PHP_EOL;

        $this->loop->addPeriodicTimer(300, function () {
            echo sprintf(
                '[%s] Current usage %f mb, Max: %f mb' . PHP_EOL,
                (new DateTime())->format('Y-m-d H:i:s'),
                round(memory_get_usage() / 1024 / 1024, 2) . ' mb',
                round(memory_get_peak_usage() / 1024 / 1024, 2) . ' mb'
            );
        });

        return Command::SUCCESS;
    }

    protected function addRoutes(Router $router): void
    {
        foreach ($this->getControllers() as $controller) {
            $controller->addRoutes($router);
        }
    }

    protected function addMiddlewares(Router $router): void
    {
        foreach ($this->getControllers() as $controller) {
            if ($controller->hasMiddlewares()) {
                foreach ($controller->getMiddlewares(AbstractController::BEFORE_MIDDLEWARE) as $middleware) {
                    $router->beforeMiddleware($middleware[0], $middleware[1]);
                }
                foreach ($controller->getMiddlewares(AbstractController::AFTER_MIDDLEWARE) as $middleware) {
                    $router->afterMiddleware($middleware[0], $middleware[1]);
                }
            }
        }
    }
}
