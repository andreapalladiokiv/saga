<?php

declare(strict_types=1);

namespace Techork\Saga;

/**
 * Optional: lets a saga choose the queue and connection its steps run on.
 *
 * Routing used to be read off dynamic properties (`$saga->queue ?? null`), which
 * conflates "no such property" with "the property is protected or private" —
 * so `protected string $queue = 'shipping'`, the normal visibility for a
 * hand-written property, silently routed to the default queue while a dedicated
 * worker sat idle. Expressing it as an interface turns a mis-declaration into a
 * compile-time obligation.
 *
 * Return null from either method to fall back to the queue's own defaults.
 */
interface SagaRouting
{
    public function connection(): ?string;

    public function queue(): ?string;
}
