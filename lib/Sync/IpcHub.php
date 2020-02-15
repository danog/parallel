<?php

namespace Amp\Parallel\Sync;

use Amp\ByteStream\ResourceOutputStream;
use Amp\Deferred;
use Amp\Loop;
use Amp\Parallel\Context\ContextException;
use Amp\Promise;
use Amp\Socket\PendingAcceptError;
use Amp\Socket\ResourceSocket;
use Amp\Socket\Socket;
use Amp\Success;
use Amp\TimeoutCancellationToken;
use Amp\TimeoutException;
use function Amp\call;
use function Amp\Socket\connect;

class IpcHub
{
    const PROCESS_START_TIMEOUT = 5000;
    const CONNECT_TIMEOUT = 1000;
    const KEY_RECEIVE_TIMEOUT = 1000;

    /** @var int */
    private $keyLength;

    /** @var resource|null */
    private $server;

    /** @var string|null */
    private $uri;

    /** @var int[] */
    private $keys;

    /** @var string|null */
    private $watcher;

    /** @var Deferred[]|Deferred */
    private $acceptor;

    /** @var string|null */
    private $toUnlink;

    /**
     * @param string $uri IPC server URI.
     * @param string $key Key for this connection attempt.
     *
     * @return Promise<Socket>
     */
    public static function connect(string $uri, string $key = ''): Promise
    {
        return call(function () use ($uri, $key): \Generator {
            $type = \filetype($uri);
            if ($type === 'fifo') {
                $suffix = \bin2hex(\random_bytes(10));
                $prefix = \sys_get_temp_dir()."/amp-".$suffix.".fifo";

                if (\strlen($prefix) > 0xFFFF) {
                    throw new \RuntimeException('Prefix is too long!');
                }

                $sockets = [
                    $prefix."2",
                    $prefix."1",
                ];

                foreach ($sockets as $k => &$socket) {
                    if (!\posix_mkfifo($socket, 0777)) {
                        throw new \RuntimeException("Could not create FIFO client socket");
                    }

                    \register_shutdown_function(static function () use ($socket): void {
                        @\unlink($socket);
                    });

                    if (!$socket = \fopen($socket, 'r+')) { // Open in r+w mode to prevent blocking if there is no reader
                        throw new \RuntimeException("Could not open FIFO client socket");
                    }
                }

                if (!$tempSocket = \fopen($uri, 'r+')) { // Open in r+w mode to prevent blocking if there is no reader
                    throw new \RuntimeException("Could not connect to FIFO server");
                }
                \stream_set_blocking($tempSocket, false);
                \stream_set_write_buffer($tempSocket, 0);

                if (!\fwrite($tempSocket, \pack('v', \strlen($prefix)).$prefix)) {
                    throw new \RuntimeException("Failure sending request to FIFO server");
                }
                \fclose($tempSocket);
                $tempSocket = null;

                $socket = ResourceSocket::fromServerSocket($sockets[0]);
                (static function ($stream) use (&$socket): void {
                    $socket->writer = new ResourceOutputStream($stream);
                })->bindTo(null, ResourceSocket::class)($sockets[1]);

                if (\strlen($key)) {
                    yield $socket->write($key);
                }

                return $socket;
            }
            if ($type === 'file') {
                $uri = \file_get_contents($uri);
            } else {
                $uri = "unix://$uri";
            }

            try {
                $socket = yield connect($uri, null, new TimeoutCancellationToken(self::CONNECT_TIMEOUT));
            } catch (\Throwable $exception) {
                throw new \RuntimeException("Could not connect to IPC socket", 0, $exception);
            }

            \assert($socket instanceof Socket);

            if (\strlen($key)) {
                yield $socket->write($key);
            }

            return $socket;
        });
    }

    /**
     * Constructor.
     *
     * @param string       $uri       Local endpoint on which to listen for requests
     * @param integer      $keyLength Length of authorization key required to accept connections, if 0 authorization is disabled
     * @param boolean|null $forceFIFO Whether to force enable or force disable FIFOs for universal IPC (if null, will be chosen automatically, recommended)
     */
    public function __construct(string $uri = '', int $keyLength = 0, ?bool $forceFIFO = null)
    {
        if ($this->keyLength = $keyLength) {
            $this->acceptor = [];
        }

        if (!$uri) {
            $suffix = \bin2hex(\random_bytes(10));
            $uri = \sys_get_temp_dir()."/amp-ipc-".$suffix.".sock";
        }
        if (\file_exists($uri)) {
            @\unlink($uri);
        }
        $this->uri = $uri;

        $isWindows = \strncasecmp(\PHP_OS, "WIN", 3) === 0;

        if ($isWindows) {
            if ($forceFIFO) {
                throw new \RuntimeException("Cannot use FIFOs on windows");
            }
            $listenUri = "tcp://127.0.0.1:0";
        } else {
            $listenUri = "unix://".$uri;
        }

        if (!$forceFIFO) {
            $this->server = \stream_socket_server($listenUri, $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN);
        }

        $fifo = false;
        if (!$this->server) {
            if ($isWindows) {
                throw new \RuntimeException(\sprintf("Could not create IPC server: (Errno: %d) %s", $errno, $errstr));
            }
            if (!\posix_mkfifo($uri, 0777)) {
                throw new \RuntimeException(\sprintf("Could not create the FIFO socket, and could not create IPC server: (Errno: %d) %s", $errno, $errstr));
            }
            if (!$this->server = \fopen($uri, 'r+')) { // Open in r+w mode to prevent blocking if there is no reader
                throw new \RuntimeException(\sprintf("Could not connect to the FIFO socket, and could not create IPC server: (Errno: %d) %s", $errno, $errstr));
            }
            \stream_set_blocking($this->server, false);
            $fifo = true;
        }

        if ($isWindows) {
            $name = \stream_socket_get_name($this->server, false);
            $port = \substr($name, \strrpos($name, ":") + 1);
            \file_put_contents($this->uri, "tcp://127.0.0.1:".$port);
        }

        $isWindows = \strncasecmp(\PHP_OS, "WIN", 3) === 0;

        $keys = &$this->keys;
        $acceptor = &$this->acceptor;
        $this->watcher = Loop::onReadable($this->server, static function (string $watcher, $server) use (&$keys, &$acceptor, $fifo, $keyLength): void {
            if ($fifo) {
                $length = \unpack('v', \fread($server, 2))[1];
                if (!$length) {
                    return; // Could not accept, wrong length read
                }

                $prefix = \fread($server, $length);
                $sockets = [
                    $prefix.'1',
                    $prefix.'2',
                ];

                foreach ($sockets as $k => &$socket) {
                    if (@\filetype($socket) !== 'fifo') {
                        if ($k) {
                            \fclose($sockets[0]);
                        }
                        return; // Is not a FIFO
                    }

                    // Open in either read or write mode to send a close signal when done
                    if (!$socket = \fopen($socket, $k ? 'w' : 'r')) {
                        if ($k) {
                            \fclose($sockets[0]);
                        }
                        return; // Could not open fifo
                    }
                }
            } else {
                // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
                if (!$socket = @\stream_socket_accept($server, 0)) {  // Timeout of 0 to be non-blocking.
                    return; // Accepting client failed.
                }
                $sockets = [$socket, $socket];
            }

            if (!$keyLength) {
                $deferred = $acceptor;
                $acceptor = null;

                \assert($deferred !== null);

                $socket = ResourceSocket::fromServerSocket($sockets[0]);
                if ($fifo) {
                    (static function ($stream) use (&$socket): void {
                        $socket->writer = new ResourceOutputStream($stream);
                    })->bindTo(null, ResourceSocket::class)($sockets[1]);
                }

                $deferred->resolve($socket);

                if (!$acceptor) {
                    Loop::disable($watcher);
                }
                return;
            }

            $delay = Loop::delay(self::KEY_RECEIVE_TIMEOUT, function () use (&$read, &$sockets, $fifo): void {
                Loop::cancel($read);
                \fclose($sockets[0]);
                if ($fifo) {
                    \fclose($sockets[1]);
                }
            });

            $read = Loop::onReadable($sockets[0], function (string $watcher, $socket) use (&$acceptor, &$keys, &$sockets, $keyLength, $delay, $fifo): void {
                static $key = "";

                // Error reporting suppressed since fread() emits E_WARNING if reading fails.
                $chunk = @\fread($socket, $keyLength - \strlen($key));

                if ($chunk === false) {
                    Loop::cancel($delay);
                    Loop::cancel($watcher);
                    \fclose($socket);
                    if ($fifo) {
                        \fclose($sockets[1]);
                    }
                    return;
                }

                $key .= $chunk;

                if (\strlen($key) < $keyLength) {
                    return; // Entire key not received, await readability again.
                }

                Loop::cancel($delay);
                Loop::cancel($watcher);

                if (!isset($keys[$key])) {
                    \fclose($socket);
                    if ($fifo) {
                        \fclose($sockets[1]);
                    }
                    return; // Ignore possible foreign connection attempt.
                }

                $pid = $keys[$key];

                \assert(isset($acceptor[$pid]), 'Invalid PID in process key list');

                $deferred = $acceptor[$pid];
                unset($acceptor[$pid], $keys[$key]);

                $socket = ResourceSocket::fromServerSocket($sockets[0]);
                if ($fifo) {
                    (static function ($stream) use (&$socket): void {
                        $socket->writer = new ResourceOutputStream($stream);
                    })->bindTo(null, ResourceSocket::class)($sockets[1]);
                }
                $deferred->resolve($socket);
            });
        });

        Loop::disable($this->watcher);
    }

    public function __destruct()
    {
        Loop::cancel($this->watcher);
        if (!$this->keyLength && $this->acceptor) {
            $this->acceptor->resolve();
            $this->acceptor = null;
        }
        \fclose($this->server);
        if ($this->uri !== null) {
            @\unlink($this->uri);
        }
    }

    /**
     * @return string The IPC server URI.
     */
    final public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * @param int $id Generate a key that will be used for the given identifier.
     *
     * @return string Key that the process should be used with {@see IpcHub::connect()} in the other process.
     */
    final public function generateKey(int $id): string
    {
        if (!$this->keyLength) {
            throw new \RuntimeException("Authentication was disabled. Please enable it by providing a key length to the constructor.");
        }
        $key = \random_bytes($this->keyLength);
        $this->keys[$key] = $id;
        return $key;
    }

    /**
     * @param int $id Wait for this ID to connect.
     *
     * @return Promise<ResourceSocket[]>
     */
    final public function accept(int $id): Promise
    {
        if (!$this->keyLength) {
            if ($this->acceptor) {
                throw new PendingAcceptError;
            }

            if (!$this->server) {
                return new Success; // Resolve with null when server is closed.
            }

            $this->acceptor = new Deferred;
            Loop::enable($this->watcher);

            return $this->acceptor->promise();
        }
        return call(function () use ($id): \Generator {
            $this->acceptor[$id] = new Deferred;

            Loop::enable($this->watcher);

            try {
                $socket = yield Promise\timeout($this->acceptor[$id]->promise(), self::PROCESS_START_TIMEOUT);
            } catch (TimeoutException $exception) {
                $key = \array_search($id, $this->keys, true);
                \assert(\is_string($key), "Key for {$id} not found");
                unset($this->acceptor[$id], $this->keys[$key]);
                throw new ContextException("Starting the process timed out", 0, $exception);
            } finally {
                if (empty($this->acceptor)) {
                    Loop::disable($this->watcher);
                }
            }

            return $socket;
        });
    }
}
