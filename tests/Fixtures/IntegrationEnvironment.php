<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Tests\Fixtures;

final class IntegrationEnvironment
{
    /**
     * @param list<string> $drivers
     * @return list<string>
     */
    public static function configuredDatabaseDrivers(array $drivers): array
    {
        $expected = array_values(array_filter(
            $drivers,
            self::databaseDriverExpected(...),
        ));
        $configured = array_values(array_filter(
            $expected,
            self::databaseConfigured(...),
        ));

        if (self::githubActions() && count($configured) !== count($expected)) {
            $missing = array_values(array_diff($expected, $configured));

            throw new \RuntimeException(sprintf(
                'GitHub Actions is missing required database integration configuration: %s.',
                implode(', ', $missing),
            ));
        }

        return $configured;
    }

    public static function databaseConfigured(string $driver): bool
    {
        if (!self::databaseDriverAvailable($driver)) {
            return false;
        }

        $database = getenv('IC_SERVICE_DATABASE');
        $username = $driver === 'mssql'
            ? getenv('IC_MSSQL_USER')
            : getenv('IC_SERVICE_USERNAME');

        return is_string($database)
            && $database !== ''
            && is_string($username)
            && $username !== '';
    }

    public static function memcachedConfigured(): bool
    {
        $configured = extension_loaded('memcached')
            && class_exists(\Memcached::class)
            && self::environmentPairConfigured('IC_MEMCACHED_HOST', 'IC_MEMCACHED_PORT');

        if (!$configured && self::githubActions()) {
            throw new \RuntimeException(
                'GitHub Actions is missing the required Memcached integration configuration.',
            );
        }

        return $configured;
    }

    /** @return array<string,array{string,string,string,string}> */
    public static function redisBackendCases(): array
    {
        if (!extension_loaded('redis') || !class_exists(\Redis::class)) {
            if (self::githubActions()) {
                throw new \RuntimeException(
                    'GitHub Actions requires the redis extension for integration tests.',
                );
            }

            return [];
        }

        $definitions = [
            'redis' => ['redis', 'IC_REDIS_HOST', 'IC_REDIS_PORT', 'IC_REDIS_PASSWORD'],
            'valkey' => ['valkey', 'IC_VALKEY_HOST', 'IC_VALKEY_PORT', 'IC_VALKEY_PASSWORD'],
        ];
        $configured = array_filter(
            $definitions,
            static fn(array $case): bool => self::environmentPairConfigured($case[1], $case[2]),
        );

        if (self::githubActions() && count($configured) !== count($definitions)) {
            $missing = array_values(array_diff(array_keys($definitions), array_keys($configured)));

            throw new \RuntimeException(sprintf(
                'GitHub Actions is missing required Redis-compatible integration configuration: %s.',
                implode(', ', $missing),
            ));
        }

        return $configured;
    }

    private static function databaseDriverExpected(string $driver): bool
    {
        if (!self::databaseDriverAvailable($driver)) {
            return false;
        }
        if ($driver !== 'mssql') {
            return true;
        }

        $username = getenv('IC_MSSQL_USER');

        return is_string($username) && $username !== '';
    }

    private static function databaseDriverAvailable(string $driver): bool
    {
        $pdoDriver = match ($driver) {
            'mysql', 'mariadb' => 'mysql',
            'pgsql' => 'pgsql',
            'mssql' => 'sqlsrv',
            default => $driver,
        };

        return in_array($pdoDriver, \PDO::getAvailableDrivers(), true);
    }

    private static function environmentPairConfigured(string $first, string $second): bool
    {
        $firstValue = getenv($first);
        $secondValue = getenv($second);

        return is_string($firstValue)
            && $firstValue !== ''
            && is_string($secondValue)
            && $secondValue !== '';
    }

    private static function githubActions(): bool
    {
        return getenv('GITHUB_ACTIONS') === 'true';
    }
}
