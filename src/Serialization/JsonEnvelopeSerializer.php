<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Serialization;

use Infocyph\Omnibus\Envelope\Envelope;

final readonly class JsonEnvelopeSerializer implements EnvelopeSerializer
{
    /** @var positive-int */
    private int $maximumBytes;

    /** @var int<2, max> */
    private int $maximumDepth;

    /** @var positive-int */
    private int $maximumStamps;

    public function __construct(
        private MessageCodecRegistry $messages,
        private StampCodecRegistry $stamps,
        int $maximumBytes = 262_144,
        int $maximumDepth = 32,
        int $maximumStamps = 64,
    ) {
        $this->maximumBytes = self::positive($maximumBytes, 'Maximum bytes');
        $this->maximumDepth = self::depth($maximumDepth);
        $this->maximumStamps = self::positive($maximumStamps, 'Maximum stamps');
    }

    public function decode(string $payload): Envelope
    {
        if ($payload === '' || strlen($payload) > $this->maximumBytes) {
            throw new \LengthException('Envelope payload is empty or exceeds the configured limit.');
        }

        $decoded = json_decode($payload, true, $this->maximumDepth, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded['version'] ?? null) !== 1) {
            throw new \UnexpectedValueException('Unsupported or malformed envelope version.');
        }

        $message = self::stringMap($decoded['message'] ?? null, 'message');
        $stamps = $decoded['stamps'] ?? null;
        if (
            !is_array($stamps)
            || !array_is_list($stamps)
            || count($stamps) > $this->maximumStamps
        ) {
            throw new \UnexpectedValueException('Malformed envelope payload.');
        }

        $messageType = self::string($message, 'type');
        $messageData = self::stringMap($message['data'] ?? null, 'message.data');
        $decodedStamps = [];
        foreach ($stamps as $stamp) {
            $stamp = self::stringMap($stamp, 'stamp');
            $stampType = self::string($stamp, 'type');
            $stampData = self::scalarMap($stamp['data'] ?? null, 'stamp.data');
            $decodedStamps[] = $this->stamps
                ->forAlias($stampType)
                ->decode($stampData);
        }

        return new Envelope(
            $this->messages->forAlias($messageType)->decode($messageData),
            $decodedStamps,
        );
    }

    public function encode(Envelope $envelope): string
    {
        $messageCodec = $this->messages->forMessage($envelope->message);
        $encodedStamps = [];
        foreach ($envelope->stamps() as $stamp) {
            if (count($encodedStamps) >= $this->maximumStamps) {
                throw new \LengthException('Envelope stamp limit exceeded.');
            }
            $codec = $this->stamps->forStamp($stamp);
            $encodedStamps[] = [
                'type' => $codec->alias(),
                'data' => $codec->encode($stamp),
            ];
        }

        $payload = json_encode(
            [
                'version' => 1,
                'message' => [
                    'type' => $messageCodec->alias(),
                    'data' => $messageCodec->encode($envelope->message),
                ],
                'stamps' => $encodedStamps,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            $this->maximumDepth,
        );
        if (strlen($payload) > $this->maximumBytes) {
            throw new \LengthException('Encoded envelope exceeds the configured payload limit.');
        }

        return $payload;
    }

    /** @return int<2, max> */
    private static function depth(int $value): int
    {
        if ($value < 2) {
            throw new \InvalidArgumentException('Maximum depth must be at least two.');
        }

        return $value;
    }

    /** @return positive-int */
    private static function positive(int $value, string $field): int
    {
        if ($value < 1) {
            throw new \InvalidArgumentException(sprintf('%s must be positive.', $field));
        }

        return $value;
    }

    /** @return array<string, bool|float|int|string|null> */
    private static function scalarMap(mixed $value, string $field): array
    {
        $values = self::stringMap($value, $field);
        foreach ($values as $item) {
            if (
                !is_bool($item)
                && !is_float($item)
                && !is_int($item)
                && !is_string($item)
                && $item !== null
            ) {
                throw new \UnexpectedValueException(
                    sprintf('Envelope field "%s" must contain only scalar values.', $field),
                );
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Envelope field "%s" must be a string.', $key));
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function stringMap(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException(sprintf('Envelope field "%s" must be an object.', $field));
        }
        $resolved = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    sprintf('Envelope field "%s" must have string keys.', $field),
                );
            }
            $resolved[$key] = $item;
        }

        return $resolved;
    }
}
