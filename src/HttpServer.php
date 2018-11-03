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

namespace Concurrent\Http;

use Concurrent\Task;
use Concurrent\Network\Server;
use Concurrent\Network\SocketException;
use Concurrent\Network\SocketStream;
use Concurrent\Network\TcpSocket;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class HttpServer extends HttpCodec
{
    protected $request;
    
    protected $response;

    protected $server;

    protected $handler;

    protected $logger;
    
    protected $upgrades = [];

    public function __construct(ServerRequestFactoryInterface $request, ResponseFactoryInterface $response, Server $server, RequestHandlerInterface $handler, ?LoggerInterface $logger = null)
    {
        $this->request = $request;
        $this->response = $response;
        $this->server = $server;
        $this->handler = $handler;
        $this->logger = $logger ?? new NullLogger();
    }
    
    public function withUpgradeHandler(UpgradeHandler $handler): HttpServer
    {
        $server = clone $this;
        $server->upgrades[$handler->getProtocol()] = $handler;

        return $server;
    }

    public function run(): void
    {
        while (true) {
            try {
                $socket = $this->server->accept();
            } catch (SocketException $e) {
                continue;
            }

            Task::async(\Closure::fromCallable([
                $this,
                'handleSocket'
            ]), $socket);
        }
    }

    protected function handleSocket(SocketStream $socket)
    {
        $buffer = '';

        try {
            do {
                $socket->setOption(TcpSocket::NODELAY, false);

                if (null === ($request = $this->readRequest($socket, $buffer))) {
                    break;
                }

                $tokens = \array_fill_keys(\array_map('strtolower', \preg_split("'\s*,\s*'", $request->getHeaderLine('Connection'))), true);

                if (!empty($tokens['upgrade'])) {
                    $protocol = \strtolower($request->getHeaderLine('Upgrade'));

                    if (isset($this->upgrades[$protocol])) {
                        return $this->handleUpgrade($this->upgrades[$protocol], $socket, $buffer, $request);
                    }

                    $this->logger->warning('No upgrade handler found for {protocol}', [
                        'protocol' => $protocol
                    ]);
                }

                $response = $this->handler->handle($request);
                $response = $response->withoutHeader('Connection');

                if ($request->getProtocolVersion() == '1.0') {
                    $close = empty($tokens['keep-alive']);
                } else {
                    $close = !empty($tokens['close']);
                }

                $this->sendResponse($socket, $request, $response, $close);
            } while (!$close);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('%s: %s', \get_class($e), $e->getMessage()), [
                'exception' => $e
            ]);

            throw $e;
        } finally {
            $socket->close();
        }
    }
    
    protected function handleUpgrade(UpgradeHandler $handler, SocketStream $socket, string $buffer, ServerRequestInterface $request)
    {
        $response = $this->response->createResponse(101);
        $response = $response->withHeader('Connection', 'upgrade');

        $response = $handler->populateResponse($request, $response);
        $close = false;

        $socket->setOption(TcpSocket::NODELAY, true);

        $this->sendResponse($socket, $request, $response, $close);

        $handler->handleConnection(new UpgradeStream($request, $response, $socket, $buffer));
    }

    protected function readRequest(SocketStream $socket, string & $buffer): ?ServerRequestInterface
    {
        $remaining = 0x4000;

        while (false === ($pos = \strpos($buffer, "\r\n\r\n"))) {
            if ($remaining == 0) {
                throw new \RuntimeException('Maximum HTTP header size exceeded');
            }

            $chunk = $socket->read($remaining);

            if ($chunk === null) {
                if ($buffer === '') {
                    return null;
                }

                throw new \RuntimeException('Failed to read next HTTP request');
            }

            $buffer .= $chunk;
            $remaining -= \strlen($chunk);
        }

        $header = \substr($buffer, 0, $pos + 2);
        $buffer = \substr($buffer, $pos + 4);

        $pos = \strpos($header, "\n");
        $line = \substr($header, 0, $pos);
        $m = null;

        if (!\preg_match("'^\s*(\S+)\s+(\S+)\s+HTTP/(1\\.[01])\s*$'is", $line, $m)) {
            throw new \RuntimeException('Invalid HTTP request line received');
        }
        
        $request = $this->request->createServerRequest($m[1], $m[2]);
        $request = $request->withProtocolVersion($m[3]);
        $request = $this->populateHeaders($request, \substr($header, $pos + 1));

        if (false !== ($i = \strpos($m[2], '?'))) {
            $query = null;
            \parse_str(\substr($m[2], $i + 1), $query);

            $request = $request->withQueryParams((array) $query);
        }

        return $this->decodeBody($socket, $request, $buffer);
    }

    protected function normalizeResponse(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        static $remove = [
            'Content-Length',
            'Keep-Alive',
            'TE',
            'Trailer',
            'Transfer-Encoding'
        ];

        foreach ($remove as $name) {
            $response = $response->withoutHeader($name);
        }

        $response = $response->withProtocolVersion($request->getProtocolVersion());
        $response = $response->withHeader('Date', \gmdate(self::DATE_RFC1123));

        return $response;
    }

    protected function sendResponse(SocketStream $socket, ServerRequestInterface $request, ResponseInterface $response, bool & $close): void
    {
        $body = $request->getBody();

        try {
            while (!$body->eof()) {
                $body->read(0xFFFF);
            }
        } finally {
            $body->close();
        }

        $response = $this->normalizeResponse($request, $response);
        $body = $response->getBody();

        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            if ($body->eof()) {
                $this->writeHeader($socket, $response, '', $close, 0, !$close);
                return;
            }

            $chunk = '';
            $eof = false;
            $i = 0;

            while (!$eof && ($i = \strlen($chunk)) < 0x8000) {
                $chunk .= $body->read(0x8000 - $i);
                $eof = $body->eof();
            }

            if ($eof) {
                $this->writeHeader($socket, $response, $chunk, $close, \strlen($chunk), !$close);
                return;
            }

            if ($request->getProtocolVersion() == '1.0') {
                $close = true;

                $this->writeHeader($socket, $response, $chunk, $close, -1);

                do {
                    $socket->write($chunk = $body->read(0xFFFF));
                } while (!$body->eof());

                return;
            }

            $this->writeHeader($socket, $response, \sprintf("%x\r\n%s\r\n", \strlen($chunk), $chunk), $close, -1);

            do {
                $chunk = $body->read(0xFFFF);

                if ($eof = $body->eof()) {
                    $chunk = \sprintf("%x\r\n%s\r\n0\r\n\r\n", \strlen($chunk), $chunk);
                    
                    if (!$close) {
                        $socket->setOption(TcpSocket::NODELAY, true);
                    }
                } else {
                    $chunk = \sprintf("%x\r\n%s\r\n", \strlen($chunk), $chunk);
                }

                $socket->write($chunk);
            } while (!$eof);
        } finally {
            $body->close();
        }
    }
    
    protected function writeHeader(SocketStream $socket, ResponseInterface $response, string $contents, bool $close, int $len, bool $nodelay = false): void
    {
        if (!$response->hasHeader('Connection')) {
            if ($close) {
                $response = $response->withHeader('Connection', 'close');
            } else {
                $response = $response->withHeader('Connection', 'keep-alive');
            }
        }
        
        if ($len < 0) {
            if ($response->getProtocolVersion() != '1.0') {
                $response = $response->withHeader('Transfer-Encoding', 'chunked');
            }
        } else {
            $response = $response->withHeader('Content-Length', (string) $len);
        }

        $reason = \trim($response->getReasonPhrase());

        $buffer = \sprintf("HTTP/%s %u%s\r\n", $response->getProtocolVersion(), $response->getStatusCode(), \rtrim(' ' . $reason));

        foreach ($response->getHeaders() as $k => $values) {
            foreach ($values as $v) {
                $buffer .= \sprintf("%s: %s\r\n", $k, $v);
            }
        }
        
        $socket->write($buffer . "\r\n" . $contents);
        
        if ($nodelay) {
            $socket->setOption(TcpSocket::NODELAY, true);
        }
    }
}
