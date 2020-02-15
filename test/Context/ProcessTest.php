<?php

namespace Amp\Parallel\Test\Context;

use Amp\Loop;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\Process;
use Amp\Parallel\Sync\IpcHub;

class ProcessTest extends AbstractContextTest
{
    public function createContext($script): Context
    {
        Loop::setState(Process::class, new IpcHub('', 32, false));
        return new Process($script);
    }
}
