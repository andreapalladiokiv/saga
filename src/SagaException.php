<?php

declare(strict_types=1);

namespace Techork\Saga;

use RuntimeException;

/**
 * Base exception for the saga library.
 *
 * Raised for: missing state, missing handler, optimistic-lock failure and
 * similar operational issues. Step failures throw {@see SagaFailedException}
 * which extends this one.
 */
class SagaException extends RuntimeException
{
}
