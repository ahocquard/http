<?php

/*
 * This file is part of Concurrent PHP HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

use Concurrent\Deferred;
use Concurrent\SignalWatcher;
use Concurrent\Timer;
use Concurrent\Http\HttpServer;
use Concurrent\Http\HttpServerConfig;
use Concurrent\Http\StreamAdapter;
use Concurrent\Http\Http2\Http2Driver;
use Concurrent\Network\TcpServer;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(-1);
ini_set('display_errors', (DIRECTORY_SEPARATOR == '\\') ? '0' : '1');

$logger = new Logger('HTTP', [], [
    new PsrLogMessageProcessor()
]);

$factory = new Psr17Factory();

$handler = new class($factory, $logger) implements RequestHandlerInterface {

    protected $factory;

    protected $logger;

    public function __construct(Psr17Factory $factory, LoggerInterface $logger)
    {
        $this->factory = $factory;
        $this->logger = $logger;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->logger->debug('{method} {target} HTTP/{version}', [
            'method' => $request->getMethod(),
            'target' => $request->getRequestTarget(),
            'version' => $request->getProtocolVersion()
        ]);

        $path = $request->getUri()->getPath();

        if ($path == '/favicon.ico') {
            return $this->factory->createResponse(404);
        }

        if ($path == '/sse') {
            $response = $this->factory->createResponse();
            $response = $response->withHeader('Content-Type', 'text/html');
            $response = $response->withBody($this->factory->createStream(file_get_contents(__DIR__ . '/sse.html')));

            return $response;
        }

        if ($path == '/stream') {
            $response = $this->factory->createResponse();
            $response = $response->withHeader('Content-Type', 'text/event-stream');
            $response = $response->withHeader('Cache-Control', 'no-cache');
            $response = $response->withHeader('X-Stream-Body', 'yes');

            $response = $response->withBody(new class() extends StreamAdapter {

                protected $count = 0;

                protected function readNextChunk(): string
                {
                    if (++$this->count > random_int(3, 7)) {
                        return '';
                    }

                    (new Timer(600))->awaitTimeout();

                    return sprintf("data: FOO #%d\n\n", $this->count);
                }
            });

            return $response;
        }

        $response = $this->factory->createResponse();
        $response = $response->withHeader('Content-Type', 'application/json');

        return $response->withBody($this->factory->createStream(\json_encode([
            'controller' => __FILE__,
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $request->getQueryParams()
        ])));
    }
};

$wait = function () {
    if (empty($_SERVER['argv'][1] ?? null)) {
        (new SignalWatcher(SignalWatcher::SIGINT))->awaitSignal();
    } else {
        (new Timer(4000))->awaitTimeout();
    }
};

$config = new HttpServerConfig($factory, $factory);
$config = $config->withHttp2Driver(new Http2Driver([], $logger));

$server = new HttpServer($config, $logger);

$tls = $server->createEncryption();
$tls = $tls->withDefaultCertificate(__DIR__ . '/cert/localhost.pem', null, 'localhost');

$tcp = TcpServer::listen('127.0.0.1', 8080, $tls);

for ($i = 0; $i < 3; $i++) {
    $listener = $server->run($tcp, $handler, $tls);

    $logger->info('HTTP server listening on tcp://{address}:{port}', [
        'address' => $tcp->getAddress(),
        'port' => $tcp->getPort()
    ]);

    $wait();

    $logger->info('HTTP server shutdown requested');

    Deferred::transform($listener->shutdown(), function () use ($logger) {
        $logger->info('Shutdown completed');
    });
}
