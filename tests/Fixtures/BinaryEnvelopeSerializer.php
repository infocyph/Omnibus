<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Tests\Fixtures;

use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Serialization\EnvelopeSerializer;

final readonly class BinaryEnvelopeSerializer implements EnvelopeSerializer
{
    private const string PREFIX = "\x00\xFFomnibus-test\x80";

    public function __construct(private EnvelopeSerializer $inner) {}

    public static function make(): self
    {
        return new self(TestSerializer::make());
    }

    public function decode(string $payload): Envelope
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            throw new \UnexpectedValueException('Binary test serializer prefix is missing.');
        }

        return $this->inner->decode(substr($payload, strlen(self::PREFIX)));
    }

    public function encode(Envelope $envelope): string
    {
        return self::PREFIX . $this->inner->encode($envelope);
    }
}
