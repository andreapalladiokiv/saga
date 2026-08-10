<?php

declare(strict_types=1);

namespace Techork\Saga\Tests\Laravel;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher as LaravelDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Workflow;
use Techork\Saga\Laravel\LaravelEventDispatcherAdapter;
use Techork\Saga\SagaMarkingStore;
use Techork\Saga\Tests\TestSubject;

/**
 * The adapter's whole job is to be a faithful Symfony dispatcher. Laravel's
 * dispatcher has two behaviours Symfony's does not, and both change what user
 * code sees.
 */
final class LaravelEventDispatcherAdapterTest extends TestCase
{
    private LaravelDispatcher $laravel;

    private LaravelEventDispatcherAdapter $adapter;

    protected function setUp(): void
    {
        $this->laravel = new LaravelDispatcher(new Container);
        $this->adapter = new LaravelEventDispatcherAdapter($this->laravel);
    }

    public function testAListenerReturningFalseDoesNotSilenceTheRemainingListeners(): void
    {
        // Illuminate\Events\Dispatcher::invokeListeners breaks its loop when a
        // listener returns boolean false. Returning a bool is idiomatic
        // Laravel, and an arrow-fn predicate does it by accident — which used
        // to disable every later listener for that event, INCLUDING the guard
        // that would have blocked the transition.
        $ran = [];
        $event = $this->guardEvent();

        $this->laravel->listen('workflow.PaySaga.guard.capture', static function () use (&$ran): bool {
            $ran[] = 'fraud-check';

            return false;   // "not suspicious"
        });
        $this->laravel->listen('workflow.PaySaga.guard.capture', static function (GuardEvent $e) use (&$ran): void {
            $ran[] = 'unpaid-check';
            $e->setBlocked(true);
        });

        $this->adapter->dispatch($event, 'workflow.PaySaga.guard.capture');

        self::assertSame(['fraud-check', 'unpaid-check'], $ran);
        self::assertTrue($event->isBlocked(), 'the guard that blocks must still run');
    }

    public function testStopPropagationIsHonoured(): void
    {
        // Every Symfony Workflow event is a PSR-14 StoppableEvent. Laravel has
        // no concept of propagation stopping, so delegating the loop to it ran
        // listeners the author had explicitly cut off.
        $ran = [];
        $event = $this->guardEvent();

        $this->laravel->listen('workflow.PaySaga.guard.capture', static function (GuardEvent $e) use (&$ran): void {
            $ran[] = 'first';
            $e->stopPropagation();
        });
        $this->laravel->listen('workflow.PaySaga.guard.capture', static function () use (&$ran): void {
            $ran[] = 'second';
        });

        $this->adapter->dispatch($event, 'workflow.PaySaga.guard.capture');

        self::assertSame(['first'], $ran);
    }

    public function testTheEventObjectIsPassedThroughAndReturned(): void
    {
        $event = $this->guardEvent();
        $seen = null;

        $this->laravel->listen('some.event', static function (GuardEvent $e) use (&$seen): void {
            $seen = $e;
        });

        $returned = $this->adapter->dispatch($event, 'some.event');

        self::assertSame($event, $seen);
        self::assertSame($event, $returned);
    }

    public function testDispatchWithoutAnExplicitNameFallsBackToTheEventClass(): void
    {
        $event = $this->guardEvent();
        $ran = false;

        $this->laravel->listen(GuardEvent::class, static function () use (&$ran): void {
            $ran = true;
        });

        $this->adapter->dispatch($event);

        self::assertTrue($ran);
    }

    public function testAnEventWithNoListenersIsHarmless(): void
    {
        $event = $this->guardEvent();

        self::assertSame($event, $this->adapter->dispatch($event, 'nobody.listening'));
    }

    private function guardEvent(): GuardEvent
    {
        $definition = new Definition(
            ['pending', 'captured'],
            [$transition = new Transition('capture', 'pending', 'captured')],
            ['pending'],
        );
        $workflow = new Workflow($definition, new SagaMarkingStore, null, 'PaySaga');
        $marking = new Marking(['pending' => 1]);

        return new GuardEvent(new TestSubject, $marking, $transition, $workflow);
    }
}
