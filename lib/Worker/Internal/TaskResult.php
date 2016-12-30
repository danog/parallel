<?php

namespace Amp\Parallel\Worker\Internal;

use Interop\Async\Promise;

abstract class TaskResult {
    /** @var string Task identifier. */
    private $id;
    
    /**
     * @param string $id Task identifier.
     */
    public function __construct(string $id) {
        $this->id = $id;
    }
    
    /**
     * @return string Task identifier.
     */
    public function getId(): string {
        return $this->id;
    }
    
    /**
     * @return \Interop\Async\Promise<mixed> Resolved with the task result or failure reason.
     */
    abstract public function promise(): Promise;
}