<?php

namespace Amp\Parallel\Worker\Internal;

use Amp\{ Coroutine, Failure, Success };
use Amp\Parallel\{ Sync\Channel, Worker\Environment };
use Interop\Async\Promise;

class TaskRunner {
    /** @var \Amp\Parallel\Sync\Channel */
    private $channel;

    /** @var \Amp\Parallel\Worker\Environment */
    private $environment;

    public function __construct(Channel $channel, Environment $environment) {
        $this->channel = $channel;
        $this->environment = $environment;
    }
    
    /**
     * Runs the task runner, receiving tasks from the parent and sending the result of those tasks.
     *
     * @return \Interop\Async\Promise
     */
    public function run(): Promise {
        return new Coroutine($this->execute());
    }
    
    /**
     * @coroutine
     *
     * @return \Generator
     */
    private function execute(): \Generator {
        $job = yield $this->channel->receive();

        while ($job instanceof Job) {
            $task = $job->getTask();
            
            try {
                $result = $task->run($this->environment);
                
                if ($result instanceof \Generator) {
                    $result = new Coroutine($result);
                }
                
                if (!$result instanceof Promise) {
                    $result = new Success($result);
                }
            } catch (\Throwable $exception) {
                $result = new Failure($exception);
            }
            
            $result->when(function ($exception, $value) use ($job) {
                if ($exception) {
                    $result = new TaskFailure($job->getId(), $exception);
                } else {
                    $result = new TaskSuccess($job->getId(), $value);
                }
    
                $this->channel->send($result);
            });

            $job = yield $this->channel->receive();
        }

        return $job;
    }
}