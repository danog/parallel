<?php

namespace Amp\Parallel\Context\Internal;

use Amp\Loop;
use Amp\Parallel\Sync\IpcHub;
use Amp\Socket\Socket;
use parallel\Events;
use parallel\Future;

class ParallelHub extends IpcHub
{
    const EXIT_CHECK_FREQUENCY = 250;

    /** @var Socket[] */
    private $sockets;

    /** @var string */
    private $watcher;

    /** @var Events */
    private $events;

    public function __construct(int $keyLength = 32)
    {
        parent::__construct('', $keyLength);

        $events = $this->events = new Events;
        $this->events->setBlocking(false);

        $sockets = &$this->sockets;
        $this->watcher = Loop::repeat(self::EXIT_CHECK_FREQUENCY, static function () use (&$sockets, $events): void {
            while ($event = $events->poll()) {
                $id = (int) $event->source;
                \assert(isset($sockets[$id]), 'Channel for context ID not found');
                $socket = $sockets[$id];
                unset($sockets[$id]);
                $socket->close();
            }
        });
        Loop::disable($this->watcher);
        Loop::unreference($this->watcher);
    }

    final public function add(int $id, Socket $socket, Future $future): void
    {
        $this->sockets[$id] = $socket;
        $this->events->addFuture((string) $id, $future);

        Loop::enable($this->watcher);
    }

    final public function remove(int $id): void
    {
        if (!isset($this->sockets[$id])) {
            return;
        }

        unset($this->sockets[$id]);
        $this->events->remove((string) $id);

        if (empty($this->sockets)) {
            Loop::disable($this->watcher);
        }
    }
}
