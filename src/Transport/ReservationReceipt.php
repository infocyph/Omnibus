<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Transport;

final class ReservationReceipt
{
    /** @return array{string,string} */
    public static function decode(string $receipt): array
    {
        if ($receipt === '' || strlen($receipt) > 1_024) {
            throw new InvalidReservation('Malformed reservation receipt.');
        }
        $padding = (4 - strlen($receipt) % 4) % 4;
        $decoded = base64_decode(
            strtr($receipt, '-_', '+/') . str_repeat('=', $padding),
            true,
        );
        if (!is_string($decoded)) {
            throw new InvalidReservation('Malformed reservation receipt.');
        }

        try {
            $parts = json_decode($decoded, true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidReservation('Malformed reservation receipt.', previous: $exception);
        }
        if (
            !is_array($parts)
            || !array_is_list($parts)
            || count($parts) !== 2
            || !is_string($parts[0])
            || $parts[0] === ''
            || !is_string($parts[1])
            || $parts[1] === ''
        ) {
            throw new InvalidReservation('Malformed reservation receipt.');
        }

        return [$parts[0], $parts[1]];
    }

    public static function encode(string $id, string $token): string
    {
        if (
            $id === ''
            || strlen($id) > 512
            || $token === ''
            || strlen($token) > 512
        ) {
            throw new \InvalidArgumentException(
                'Receipt identifiers must contain between 1 and 512 bytes.',
            );
        }

        return rtrim(
            strtr(base64_encode(json_encode([$id, $token], JSON_THROW_ON_ERROR)), '+/', '-_'),
            '=',
        );
    }
}
