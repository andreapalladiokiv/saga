<?php

declare(strict_types=1);

namespace Techork\Saga;

/**
 * In-process {@see SagaStateRepository} for tests and single-process drivers.
 *
 * It round-trips the subject through a {@see SubjectCodec} exactly as
 * {@see \Techork\Saga\Laravel\DatabaseSagaStateRepository} does, and enforces the
 * same optimistic-lock rule. That is deliberate: while it was a bare array write
 * handing back the live object and ignoring versions, every test that used it
 * proved semantics the shipped repository does not have.
 *
 * The default codec is {@see PlainSubjectCodec}, which is correct here and only
 * here — nothing outside this process can forge a payload it never stores.
 */
final class InMemorySagaStateRepository implements SagaStateRepository
{
    /** @var array<string, array{state: SagaState, subject: string}> */
    private array $rows = [];

    public function __construct(private SubjectCodec $codec = new PlainSubjectCodec()) {}

    public function load(string $id): ?SagaState
    {
        $row = $this->rows[$id] ?? null;
        if ($row === null) {
            return null;
        }

        $stored = $row['state'];

        return new SagaState(
            $stored->id,
            $stored->marking,
            $this->codec->decode($row['subject'], $id),
            $stored->history,
            $stored->version,
        );
    }

    public function save(SagaState $state): void
    {
        $payload = $this->codec->encode($state->subject);

        $existing = $this->rows[$state->id] ?? null;

        if ($state->version === 1) {
            if ($existing !== null) {
                throw SagaConcurrencyException::versionMismatch($state->id, 0);
            }
        } elseif ($existing === null) {
            throw SagaConcurrencyException::stateVanished($state->id);
        } elseif ($existing['state']->version !== $state->version - 1) {
            throw SagaConcurrencyException::versionMismatch($state->id, $state->version - 1);
        }

        $this->rows[$state->id] = [
            'state' => $state,
            'subject' => $payload,
        ];
    }

    public function delete(string $id): void
    {
        unset($this->rows[$id]);
    }

}
