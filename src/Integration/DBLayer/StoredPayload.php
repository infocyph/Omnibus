<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\DBLayer;

final class StoredPayload
{
    private const string PREFIX = '~omnibus:b64:v1~';

    public static function decode(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }

        $decoded = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if (!is_string($decoded)) {
            throw new \UnexpectedValueException('Stored Omnibus payload encoding is invalid.');
        }

        return $decoded;
    }

    public static function encode(string $payload): string
    {
        return self::PREFIX . base64_encode($payload);
    }
}
