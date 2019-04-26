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

namespace Concurrent\Http\Event;

use Concurrent\Channel;
use Concurrent\ChannelGroup;
use Concurrent\Http\StreamAdapter;

class EventServerClient extends StreamAdapter
{
    protected $id;

    protected $state;

    protected $channel;

    protected $group;

    protected $it;

    protected $primed = false;
    
    protected $callback;

    public function __construct(EventServerState $state, string $id, int $bufferSize = 64, ?callable $disconnect = null)
    {
        $this->id = $id;
        $this->state = $state;
        $this->callback = $disconnect;

        $this->channel = new Channel($bufferSize);
        $this->it = $this->channel->getIterator();

        $this->group = new ChannelGroup([
            $this->channel
        ], 0);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function close(?\Throwable $e = null)
    {
        unset($this->state->clients[$this->id]);

        $this->buffer = null;
        $this->channel->close($e);

        if ($this->callback) {
            ($this->callback)($this);
        }
    }

    public function send(Event $event, bool $block = true): void
    {
        if ($block) {
            $this->channel->send((string) $event);
        } else {
            if (null === $this->group->send((string) $event)) {
                $this->close($e = new \Error('Disconnected slow client'));

                throw $e;
            }
        }
    }

    public function append(string $event)
    {
        if (null === $this->group->send($event)) {
            $this->close(new \Error('Disconnected slow client'));
        }
    }

    protected function readNextChunk(): string
    {
        if ($this->primed) {
            $this->it->next();
        } else {
            $this->primed = true;
        }

        if (!$this->it->valid()) {
            return '';
        }

        return $this->it->current();
    }
}
