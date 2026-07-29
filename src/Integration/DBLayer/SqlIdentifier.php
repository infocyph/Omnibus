<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Integration\DBLayer;

final class SqlIdentifier
{
    public static function quote(string $identifier, string $driver): string
    {
        $segments = explode('.', $identifier);
        foreach ($segments as $segment) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $segment) !== 1) {
                throw new \InvalidArgumentException(sprintf('Invalid SQL identifier "%s".', $identifier));
            }
        }

        $quote = $driver === 'mysql' ? '`' : '"';

        return implode('.', array_map(
            static fn(string $segment): string => $quote . $segment . $quote,
            $segments,
        ));
    }
}
