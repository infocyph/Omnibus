<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\Redis;

use Infocyph\Omnibus\Envelope\AttemptStamp;
use Infocyph\Omnibus\Envelope\DelayStamp;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;
use Infocyph\Omnibus\Serialization\DecodeFailure;
use Infocyph\Omnibus\Serialization\EnvelopeSerializer;
use Infocyph\Omnibus\Transport\Duration;
use Infocyph\Omnibus\Transport\InvalidReservation;
use Infocyph\Omnibus\Transport\QueueName;
use Infocyph\Omnibus\Transport\Reservation;
use Infocyph\Omnibus\Transport\ReservationReceipt;
use Infocyph\Omnibus\Transport\Transport;
use Infocyph\UID\ULID;
use Psr\Clock\ClockInterface;

final readonly class RedisTransport implements Transport
{
    private const string ACK = <<<'LUA'
if redis.call('HGET', KEYS[4], ARGV[1]) ~= ARGV[2] then return 0 end
redis.call('ZREM', KEYS[1], ARGV[1])
redis.call('HDEL', KEYS[2], ARGV[1])
redis.call('HDEL', KEYS[3], ARGV[1])
redis.call('HDEL', KEYS[4], ARGV[1])
return 1
LUA;

    private const string RECEIVE = <<<'LUA'
local expired = redis.call('ZRANGEBYSCORE', KEYS[2], '-inf', ARGV[1], 'LIMIT', 0, ARGV[4])
for _, id in ipairs(expired) do
    redis.call('ZREM', KEYS[2], id)
    redis.call('ZADD', KEYS[1], ARGV[1], id)
    redis.call('HDEL', KEYS[5], id)
end
local ids = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, ARGV[4])
local result = {}
for _, id in ipairs(ids) do
    redis.call('ZREM', KEYS[1], id)
    redis.call('ZADD', KEYS[2], ARGV[2], id)
    local attempt = redis.call('HINCRBY', KEYS[4], id, 1)
    redis.call('HSET', KEYS[5], id, ARGV[3])
    table.insert(result, id)
    local payload = redis.call('HGET', KEYS[3], id)
    table.insert(result, payload or '')
    table.insert(result, tostring(attempt))
end
return result
LUA;

    private const string RELEASE = <<<'LUA'
if redis.call('HGET', KEYS[3], ARGV[1]) ~= ARGV[2] then return 0 end
redis.call('ZREM', KEYS[2], ARGV[1])
redis.call('ZADD', KEYS[1], ARGV[3], ARGV[1])
redis.call('HDEL', KEYS[3], ARGV[1])
return 1
LUA;

    private const string SEND = <<<'LUA'
redis.call('HSET', KEYS[2], ARGV[1], ARGV[2])
redis.call('HSET', KEYS[3], ARGV[1], 0)
redis.call('ZADD', KEYS[1], ARGV[3], ARGV[1])
return 1
LUA;

    private const string SIZE = <<<'LUA'
return redis.call('ZCOUNT', KEYS[1], '-inf', ARGV[1]) + redis.call('ZCOUNT', KEYS[2], '-inf', ARGV[1])
LUA;

    public function __construct(
        private RedisClient $client,
        private EnvelopeSerializer $serializer,
        private ClockInterface $clock,
        private string $prefix = 'omnibus',
    ) {
        if (
            $prefix === ''
            || strlen($prefix) > 191
            || preg_match('/[\x00-\x1F\x7F]/D', $prefix) === 1
            || str_contains($prefix, '{')
            || str_contains($prefix, '}')
        ) {
            throw new \InvalidArgumentException(
                'Redis key prefix must be bounded and cannot contain control characters or hash-tag braces.',
            );
        }
    }

    public function acknowledge(Reservation $reservation): void
    {
        [$id, $token] = ReservationReceipt::decode($reservation->receipt);
        $keys = $this->keys($reservation->queue);
        $changed = $this->eval(self::ACK, [
            $keys['reserved'],
            $keys['payloads'],
            $keys['attempts'],
            $keys['receipts'],
        ], [$id, $token]);
        $this->assertChanged($changed, $reservation);
    }

    public function receive(string $queue, int $limit = 1, float $visibilitySeconds = 60.0): iterable
    {
        self::validateReceive($queue, $limit, $visibilitySeconds);
        $now = $this->microseconds();
        $token = ULID::generateMonotonic();
        $keys = $this->keys($queue);
        $result = $this->eval(self::RECEIVE, array_values($keys), [
            (string) $now,
            (string) ($now + Duration::microseconds($visibilitySeconds, $now)),
            $token,
            (string) $limit,
        ]);
        if (!is_array($result) || count($result) % 3 !== 0) {
            throw new \UnexpectedValueException('Redis returned a malformed reservation batch.');
        }

        $reservations = [];
        for ($offset = 0, $count = count($result); $offset < $count; $offset += 3) {
            $id = self::scalarString($result[$offset] ?? null, 'message id');
            $payload = self::scalarString($result[$offset + 1] ?? null, 'payload');
            $attempt = self::positiveInt($result[$offset + 2] ?? null, 'attempt');
            $receipt = ReservationReceipt::encode($id, $token);

            try {
                $envelope = $this->serializer
                    ->decode($payload)
                    ->with(new AttemptStamp($attempt));
                $reservations[] = Reservation::decoded($receipt, $queue, $envelope, $attempt);
            } catch (\Throwable $failure) {
                $reservations[] = Reservation::undecodable(
                    $receipt,
                    $queue,
                    DecodeFailure::fromThrowable($payload, $failure),
                    $attempt,
                );
            }
        }

        return $reservations;
    }

    public function reject(Reservation $reservation): void
    {
        $this->acknowledge($reservation);
    }

    public function release(Reservation $reservation, float $delaySeconds = 0.0): void
    {
        if (!is_finite($delaySeconds) || $delaySeconds < 0.0) {
            throw new \InvalidArgumentException('Release delay must be a finite non-negative number.');
        }
        [$id, $token] = ReservationReceipt::decode($reservation->receipt);
        $keys = $this->keys($reservation->queue);
        $changed = $this->eval(self::RELEASE, [
            $keys['ready'],
            $keys['reserved'],
            $keys['receipts'],
        ], [
            $id,
            $token,
            (string) (($now = $this->microseconds()) + Duration::microseconds($delaySeconds, $now)),
        ]);
        $this->assertChanged($changed, $reservation);
    }

    public function send(Envelope $envelope, string $queue): Envelope
    {
        $this->assertQueue($queue);
        if (!$envelope->last(MessageIdStamp::class) instanceof MessageIdStamp) {
            $envelope = $envelope->with(new MessageIdStamp(ULID::generateMonotonic()));
        }
        $delay = $envelope->last(DelayStamp::class);
        $keys = $this->keys($queue);
        $this->eval(self::SEND, [
            $keys['ready'],
            $keys['payloads'],
            $keys['attempts'],
        ], [
            ULID::generateMonotonic(),
            $this->serializer->encode($envelope),
            (string) (
                ($now = $this->microseconds())
                + Duration::microseconds($delay instanceof DelayStamp ? $delay->seconds : 0.0, $now)
            ),
        ]);

        return $envelope;
    }

    public function size(string $queue): int
    {
        $this->assertQueue($queue);
        $keys = $this->keys($queue);
        $result = $this->eval(
            self::SIZE,
            [$keys['ready'], $keys['reserved']],
            [(string) $this->microseconds()],
        );

        return self::nonNegativeInt($result, 'queue size');
    }

    private static function nonNegativeInt(mixed $value, string $field): int
    {
        if (
            (!is_int($value) && !is_string($value))
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
        ) {
            throw new \UnexpectedValueException(sprintf('Redis %s must be a non-negative integer.', $field));
        }

        return (int) $value;
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        $resolved = self::nonNegativeInt($value, $field);
        if ($resolved < 1) {
            throw new \UnexpectedValueException(sprintf('Redis %s must be positive.', $field));
        }

        return $resolved;
    }

    private static function scalarString(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Redis %s must be a string.', $field));
        }

        return $value;
    }

    private static function validateReceive(string $queue, int $limit, float $visibilitySeconds): void
    {
        QueueName::assert($queue);
        if (str_contains($queue, '{') || str_contains($queue, '}')) {
            throw new \InvalidArgumentException('Redis queue names cannot contain hash-tag braces.');
        }
        if ($limit < 1 || !is_finite($visibilitySeconds) || $visibilitySeconds <= 0.0) {
            throw new \InvalidArgumentException(
                'Receive requires a queue, positive limit, and positive visibility timeout.',
            );
        }
        if ($limit > 1_000) {
            throw new \InvalidArgumentException('Receive limit cannot exceed 1000.');
        }
    }

    private function assertChanged(mixed $changed, Reservation $reservation): void
    {
        if (self::nonNegativeInt($changed, 'affected reservation count') !== 1) {
            throw new InvalidReservation(sprintf(
                'Reservation "%s" is no longer active.',
                $reservation->receipt,
            ));
        }
    }

    private function assertQueue(string $queue): void
    {
        QueueName::assert($queue);
        if (str_contains($queue, '{') || str_contains($queue, '}')) {
            throw new \InvalidArgumentException('Redis queue names cannot contain hash-tag braces.');
        }
    }

    /**
     * @param list<string> $keys
     * @param list<string> $arguments
     */
    private function eval(string $script, array $keys, array $arguments): mixed
    {
        return $this->client->execute(
            'EVAL',
            $script,
            (string) count($keys),
            ...$keys,
            ...$arguments,
        );
    }

    /** @return array{ready:string,reserved:string,payloads:string,attempts:string,receipts:string} */
    private function keys(string $queue): array
    {
        $tag = sprintf('{%s:%s}', $this->prefix, $queue);

        return [
            'ready' => $tag . ':ready',
            'reserved' => $tag . ':reserved',
            'payloads' => $tag . ':payloads',
            'attempts' => $tag . ':attempts',
            'receipts' => $tag . ':receipts',
        ];
    }

    private function microseconds(): int
    {
        $now = $this->clock->now();

        return ((int) $now->format('U')) * 1_000_000 + (int) $now->format('u');
    }
}
