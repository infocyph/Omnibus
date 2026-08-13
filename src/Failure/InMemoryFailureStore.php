<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Failure;

use Infocyph\Omnibus\Clock\SystemClock;
use Infocyph\Omnibus\Internal\Time;
use Infocyph\UID\ULID;
use Psr\Clock\ClockInterface;

final class InMemoryFailureStore implements FailureStore
{
    /** @var array<string, FailedMessage> */
    private array $failures = [];

    /** @var array<string, array{status: 'failed'|'retrying'|'sent', token: string|null, until: int|null}> */
    private array $retryStates = [];

    public function __construct(private readonly ClockInterface $clock = new SystemClock()) {}

    public function add(FailedMessage $failure): void
    {
        $this->failures[$failure->id] = $failure;
        $this->retryStates[$failure->id] ??= [
            'status' => 'failed',
            'token' => null,
            'until' => null,
        ];
    }

    public function all(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new \InvalidArgumentException('Failure list limit must be between 1 and 1000.');
        }

        return array_slice(array_values($this->failures), 0, $limit);
    }

    public function claimRetry(string $id, float $leaseSeconds = 30.0): FailureRetryClaim
    {
        self::validateLease($leaseSeconds);
        $failure = $this->failures[$id]
            ?? throw new FailureNotFound(sprintf('Failed message "%s" was not found.', $id));
        $now = Time::fromDate($this->clock->now());
        $state = $this->retryStates[$id];
        if ($state['status'] === 'sent' || ($state['status'] === 'retrying' && $state['until'] > $now)) {
            throw new FailureRetryClaimUnavailable(sprintf(
                'Failed message "%s" is already being retried.',
                $id,
            ));
        }

        $token = ULID::generateMonotonic();
        $expiresAt = Time::add($now, $leaseSeconds);
        $this->retryStates[$id] = [
            'status' => 'retrying',
            'token' => $token,
            'until' => $expiresAt,
        ];

        return new FailureRetryClaim($failure, $token, $expiresAt);
    }

    public function clear(): int
    {
        $count = count($this->failures);
        $this->failures = [];
        $this->retryStates = [];

        return $count;
    }

    public function find(string $id): ?FailedMessage
    {
        return $this->failures[$id] ?? null;
    }

    public function markRetrySent(FailureRetryClaim $claim): bool
    {
        $id = $claim->failure->id;
        if (!$this->matches($id, $claim->token, 'retrying')) {
            return false;
        }
        $this->retryStates[$id] = [
            'status' => 'sent',
            'token' => $claim->token,
            'until' => null,
        ];

        return true;
    }

    public function prune(\DateTimeImmutable $before): int
    {
        $removed = 0;
        foreach ($this->failures as $id => $failure) {
            if ($failure->failedAt >= $before) {
                continue;
            }
            unset($this->failures[$id]);
            unset($this->retryStates[$id]);
            $removed++;
        }

        return $removed;
    }

    public function releaseRetry(FailureRetryClaim $claim): bool
    {
        $id = $claim->failure->id;
        if (!$this->matches($id, $claim->token, 'retrying')) {
            return false;
        }
        $this->retryStates[$id] = [
            'status' => 'failed',
            'token' => null,
            'until' => null,
        ];

        return true;
    }

    public function remove(string $id): bool
    {
        if (!isset($this->failures[$id])) {
            return false;
        }
        unset($this->failures[$id]);
        unset($this->retryStates[$id]);

        return true;
    }

    public function removeRetried(FailureRetryClaim $claim): bool
    {
        $id = $claim->failure->id;
        if (!$this->matches($id, $claim->token, 'sent')) {
            return false;
        }

        return $this->remove($id);
    }

    private static function validateLease(float $leaseSeconds): void
    {
        if (!is_finite($leaseSeconds) || $leaseSeconds <= 0.0) {
            throw new \InvalidArgumentException('Failure retry claim lease must be positive and finite.');
        }
    }

    private function matches(string $id, string $token, string $status): bool
    {
        $state = $this->retryStates[$id] ?? null;

        return $state !== null && $state['status'] === $status && $state['token'] === $token;
    }
}
