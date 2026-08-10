<?php

declare(strict_types=1);

namespace Techork\Saga;

/**
 * Raised when a saga id is started twice.
 *
 * `start()` always issues a blind INSERT, so a redelivered webhook or an
 * at-least-once inbound message hits the primary key. That used to surface as a
 * raw `UniqueConstraintViolationException`, which an application's
 * `catch (SagaException)` idempotency handler does not match — so the webhook
 * returned 500 and the provider kept retrying a case that should be a clean
 * no-op. Catch this to treat a duplicate start as "already running".
 */
final class SagaAlreadyExistsException extends SagaException
{
}
