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

namespace Concurrent\Http\Http2;

use Concurrent\Channel;
use Concurrent\Deferred;
use Concurrent\Task;
use Concurrent\Http\HttpCodec;
use Concurrent\Stream\StreamClosedException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Stream
{
    protected $id;

    protected $state;

    protected $defer;

    protected $buffer;

    protected $channel;

    protected $receiveWindow;

    protected $receiveChannel;

    protected $receiveReady;

    protected $sendWindow;

    protected $sendChannel;

    protected $sendReady;

    public function __construct(int $id, ConnectionState $state)
    {
        $this->id = $id;
        $this->state = $state;

        $this->receiveWindow = $state->localSettings[Connection::SETTING_INITIAL_WINDOW_SIZE];
        $this->sendWindow = $state->remoteSettings[Connection::SETTING_INITIAL_WINDOW_SIZE];

        $this->receiveChannel = new Channel();
        $this->receiveReady = $this->receiveChannel->getIterator();

        $this->sendChannel = new Channel();
        $this->sendReady = $this->sendChannel->getIterator();
    }

    public function __debugInfo(): array
    {
        return [
            'id' => $this->id
        ];
    }

    public function close(?\Throwable $e = null): void
    {
        if (empty($this->state->streams[$this->id])) {
            return;
        }

        unset($this->state->streams[$this->id]);

        if ($this->channel !== null) {
            $channel = $this->channel;
            $this->channel = null;

            if (!$channel->isClosed()) {
                $this->state->sendFrameAsync(new Frame(Frame::RST_STREAM, $this->id, \pack('n', 0)));

                $channel->close($e);
            }
        }

        $this->receiveChannel->close($e ?? new StreamClosedException('Stream has been closed'));
        $this->sendChannel->close($e ?? new StreamClosedException('Stream has been closed'));
    }

    public function processFrame(Frame $frame): void
    {
        switch ($frame->type) {
            case Frame::HEADERS:
                $data = $frame->getPayload();

                if ($frame->flags & Frame::PRIORITY_FLAG) {
                    $data = \substr($data, 5);
                }

                $this->buffer = $data;

                if ($frame->flags & Frame::END_HEADERS) {
                    if (!($frame->flags & Frame::END_STREAM)) {
                        $this->channel = new Channel(64);
                    }

                    try {
                        if ($this->defer) {
                            $this->defer->resolve($this->buffer);
                        } else {
                            $this->acceptRequest();
                        }
                    } finally {
                        $this->buffer = null;
                    }
                }
                break;
            case Frame::CONTINUATION:
                $this->buffer = $frame->getPayload();

                if ($frame->flags & Frame::END_HEADERS) {
                    try {
                        if ($this->defer) {
                            $this->defer->resolve($this->buffer);
                        } else {
                            $this->acceptRequest();
                        }
                    } finally {
                        $this->buffer = null;
                    }
                }
                break;
            case Frame::DATA:
                $data = $frame->getPayload();
                $len = \strlen($data);

                $this->receiveWindow -= $len;
                $this->state->receiveWindow -= $len;

                if ($this->receiveWindow < 0) {
                    $this->receiveReady->next();
                }

                if ($this->state->receiveWindow < 0) {
                    $this->state->receiveReady->next();
                }

                try {
                    if ($data !== '') {
                        $this->channel->send($data);
                    }
                } finally {
                    if ($frame->flags & Frame::END_STREAM) {
                        $this->channel->close();

                        $this->close();
                    }
                }
                break;
            case Frame::WINDOW_UPDATE:
                $this->sendWindow += (int) \unpack('N', $frame->getPayload())[1];

                if ($this->sendWindow > 0 && $this->sendChannel->isReadyForSend()) {
                    $this->sendChannel->send(null);
                }
                break;
        }
    }
    
    protected function acceptRequest()
    {
        $buffer = $this->buffer;

        $this->state->requests->send(function (ServerRequestFactoryInterface $f1, ResponseFactoryInterface $f2, RequestHandlerInterface $handler, array $params = []) use ($buffer) {
            $headers = $this->state->hpack->decode($buffer);
            $path = $this->getFirstHeader(':path', $headers);

            $uri = \vsprintf('%s://%s%s', [
                $this->getFirstHeader(':scheme', $headers),
                $this->getFirstHeader(':authority', $headers),
                $path
            ]);

            $request = $f1->createServerRequest($this->getFirstHeader(':method', $headers), $uri, array_filter(array_merge($params, [
                'REQUEST_TIME' => \time(),
                'REQUEST_TIME_FLOAT' => \microtime(true)
            ])));

            $request = $request->withProtocolVersion('2.0');

            if (false !== ($i = \strpos($path, '?'))) {
                $query = null;
                \parse_str(\substr($path, $i + 1), $query);

                $request = $request->withQueryParams((array) $query);
            }

            foreach ($headers as $entry) {
                if (($entry[0][0] ?? null) !== ':') {
                    $request = $request->withAddedHeader(...$entry);
                }
            }

            if ($this->channel !== null) {
                $request = $request->withBody(new EntityStream($this, $this->channel->getIterator()));
            }

            $response = $handler->handle($request);

            $this->sendHeaders($this->encodeHeaders($response, [
                ':status' => (string) $response->getStatusCode()
            ]));

            $this->sendBody($response);
        });
    }

    public function updateReceiveWindow(int $size): void
    {
        $frame = new Frame(Frame::WINDOW_UPDATE, 0, \pack('N', $size));

        $this->state->sendFramesAsync([
            $frame,
            new Frame(Frame::WINDOW_UPDATE, $this->id, $frame->data)
        ]);

        $this->state->receiveWindow += $size;

        while ($this->state->receiveWindow > 0 && $this->state->receiveChannel->isReadyForSend()) {
            $this->state->receiveChannel->send(null);
        }

        $this->receiveWindow += $size;

        while ($this->receiveWindow > 0 && $this->receiveChannel->isReadyForSend()) {
            $this->receiveChannel->send(null);
        }
    }

    public function sendRequest(RequestInterface $request, ResponseFactoryInterface $factory): ResponseInterface
    {
        $uri = $request->getUri();
        $target = $request->getRequestTarget();

        if ($target === '*') {
            $path = '*';
        } else {
            $path = '/' . \ltrim($target, '/');
        }

        $headers = [
            ':method' => $request->getMethod(),
            ':scheme' => $uri->getScheme(),
            ':authority' => $uri->getAuthority(),
            ':path' => $path
        ];

        $this->sendHeaders($this->encodeHeaders($request, $headers, [
            'host'
        ]));

        $this->sendBody($request);

        $this->defer = new Deferred();

        try {
            $headers = $this->state->hpack->decode(Task::await($this->defer->awaitable()));
        } finally {
            $this->defer = null;
        }

        $response = $factory->createResponse((int) $this->getFirstHeader(':status', $headers));
        $response = $response->withProtocolVersion('2.0');

        foreach ($headers as $entry) {
            if (($entry[0][0] ?? null) !== ':') {
                $response = $response->withAddedHeader(...$entry);
            }
        }

        if ($this->channel !== null) {
            $response = $response->withBody(new EntityStream($this, $this->channel->getIterator()));
        }

        return $response;
    }

    protected function getFirstHeader(string $name, array $headers, string $default = ''): string
    {
        foreach ($headers as $header) {
            if ($header[0] === $name) {
                return $header[1];
            }
        }

        return $default;
    }

    protected function sendHeaders(string $headers, bool $nobody = false)
    {
        $flags = Frame::END_HEADERS | ($nobody ? Frame::END_STREAM : Frame::NOFLAG);

        if (\strlen($headers) > 0x4000) {
            $parts = \str_split($headers, 0x4000);
            $frames = [];

            $frames[] = new Frame(Frame::HEADERS, $this->id, $parts[0]);

            for ($size = \count($parts) - 2, $i = 1; $i < $size; $i++) {
                $frames[] = new Frame(Frame::CONTINUATION, $this->id, $parts[$i]);
            }

            $frames[] = new Frame(Frame::CONTINUATION, $this->id, $parts[\count($parts) - 1], $flags);

            $this->state->sendFrames($frames);
        } else {
            $this->state->sendFrame(new Frame(Frame::HEADERS, $this->id, $headers, $flags));
        }
    }

    protected function sendBody(MessageInterface $message): int
    {
        $body = $message->getBody();
        $sent = 0;

        if ($body->isSeekable()) {
            $body->rewind();
        }

        try {
            $eof = false;

            do {
                $chunk = HttpCodec::readBufferedChunk($body, 8192, $eof);
                $len = \strlen($chunk);

                if ($eof && $len == 0) {
                    $this->state->sendFrame(new Frame(Frame::DATA, $this->id, '', Frame::END_STREAM));
                }

                while ($len > 0) {
                    $available = \max(0, \min($len, $this->sendWindow, $this->state->sendWindow));

                    if ($available == 0) {
                        if ($this->sendWindow <= 0) {
                            $this->sendReady->next();
                        } else {
                            $this->state->sendReady->next();
                        }

                        continue;
                    }

                    $this->sendWindow -= $available;
                    $this->state->sendWindow -= $available;

                    if ($available < $len) {
                        $this->state->sendFrame(new Frame(Frame::DATA, $this->id, \substr($chunk, 0, $available)));

                        $chunk = \substr($chunk, $available);
                    } else {
                        $this->state->sendFrame(new Frame(Frame::DATA, $this->id, $chunk, $eof ? Frame::END_STREAM : Frame::NOFLAG));
                    }

                    $sent += $available;
                    $len -= $available;
                }
            } while (!$eof);
        } finally {
            $body->close();
        }

        return $sent;
    }

    protected function encodeHeaders(MessageInterface $message, array $headers, array $remove = [])
    {
        static $removeDefault = [
            'connection',
            'content-length',
            'keep-alive',
            'transfer-encoding',
            'te'
        ];

        foreach (\array_change_key_case($message->getHeaders(), \CASE_LOWER) as $k => $v) {
            if (!isset($headers[$k])) {
                $headers[$k] = $v;
            }
        }

        foreach ($removeDefault as $name) {
            unset($headers[$name]);
        }

        foreach ($remove as $name) {
            unset($headers[$name]);
        }

        $headerList = [];

        foreach ($headers as $k => $h) {
            if (\is_array($h)) {
                foreach ($h as $v) {
                    $headerList[] = [
                        $k,
                        $v
                    ];
                }
            } else {
                $headerList[] = [
                    $k,
                    $h
                ];
            }
        }

        return $this->state->hpack->encode($headerList);
    }
}
