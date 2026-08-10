<?php

declare(strict_types=1);

namespace Techork\Saga\Tests;

use function sprintf;

/**
 * A realistically-shaped subject: non-public properties (so serialize() emits
 * NUL bytes in the property names) and a slot for binary data. Both are things
 * a character column cannot store safely, and neither appears in
 * {@see TestSubject}, which is why the byte-safety of the subject column went
 * untested.
 */
final class SubjectWithSecrets
{
    public function __construct(
        private string $customerRef,
        protected int $amountCents,
        public string $blob = '',
    ) {}

    public function describe(): string
    {
        return sprintf('%s:%d', $this->customerRef, $this->amountCents);
    }
}
