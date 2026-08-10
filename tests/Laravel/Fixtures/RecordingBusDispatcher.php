<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel\Fixtures;

use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Minimal {@see Dispatcher} that records what was dispatched instead of
 * running it, so a job's re-dispatch behaviour can be asserted without a
 * queue, a worker, or laravel/framework.
 */
final class RecordingBusDispatcher implements Dispatcher
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch($command)
    {
        $this->dispatched[] = $command;

        return null;
    }

    public function dispatchSync($command, $handler = null)
    {
        return $this->dispatch($command);
    }

    public function dispatchNow($command, $handler = null)
    {
        return $this->dispatch($command);
    }

    public function dispatchAfterResponse($command, $handler = null)
    {
        $this->dispatch($command);
    }

    public function chain($jobs = null)
    {
        return $this;
    }

    public function hasCommandHandler($command)
    {
        return false;
    }

    public function getCommandHandler($command)
    {
        return false;
    }

    public function pipeThrough(array $pipes)
    {
        return $this;
    }

    public function map(array $map)
    {
        return $this;
    }
}
