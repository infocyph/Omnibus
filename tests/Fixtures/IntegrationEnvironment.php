<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Tests\Fixtures;

final class IntegrationEnvironment
{
    /** @var array<string,string> */
    private const array DATABASE_SERVICES = [
        'mysql' => 'mysql',
        'mariadb' => 'mariadb',
        'postgres' => 'pgsql',
        'mssql' => 'mssql',
    ];

    /**
     * @param list<string> $drivers
     * @param list<string>|null $availablePdoDrivers
     * @return list<string>
     */
    public static function configuredDatabaseDrivers(
        array $drivers,
        ?array $availablePdoDrivers = null,
    ): array {
        $services = self::requiredServices();
        $required = self::requiredDatabaseDrivers($drivers, $services);
        $candidates = $services === [] ? $drivers : $required;
        $configured = [];

        foreach ($candidates as $driver) {
            $issues = self::databaseConfigurationIssues(
                $driver,
                $availablePdoDrivers,
                $services !== [],
            );
            if ($issues === []) {
                $configured[] = $driver;

                continue;
            }
            if (in_array($driver, $required, true)) {
                throw new \RuntimeException(sprintf(
                    'Required %s database integration is unavailable: %s.',
                    $driver,
                    implode(', ', $issues),
                ));
            }
        }

        return $configured;
    }

    /** @param list<string>|null $availablePdoDrivers */
    public static function databaseConfigured(
        string $driver,
        ?array $availablePdoDrivers = null,
    ): bool {
        return self::databaseConfigurationIssues($driver, $availablePdoDrivers, false) === [];
    }

    public static function memcachedConfigured(): bool
    {
        $services = self::requiredServices();
        $required = in_array('memcached', $services, true);
        if ($services !== [] && !$required) {
            return false;
        }

        $configured = extension_loaded('memcached')
            && class_exists(\Memcached::class)
            && self::environmentPairConfigured('IC_MEMCACHED_HOST', 'IC_MEMCACHED_PORT');

        if ($required && !$configured) {
            throw new \RuntimeException(
                'Required Memcached integration is unavailable: extension or service configuration is missing.',
            );
        }

        return $configured;
    }

    /** @return array<string,array{string,string,string,string}> */
    public static function redisBackendCases(): array
    {
        $definitions = [
            'redis' => ['redis', 'IC_REDIS_HOST', 'IC_REDIS_PORT', 'IC_REDIS_PASSWORD'],
            'valkey' => ['valkey', 'IC_VALKEY_HOST', 'IC_VALKEY_PORT', 'IC_VALKEY_PASSWORD'],
        ];
        $services = self::requiredServices();
        $required = $services === []
            ? []
            : array_values(array_intersect(array_keys($definitions), $services));
        if ($services !== [] && $required === []) {
            return [];
        }
        if (!extension_loaded('redis') || !class_exists(\Redis::class)) {
            if ($required !== []) {
                throw new \RuntimeException(
                    'Required Redis-compatible integration is unavailable: redis extension is missing.',
                );
            }

            return [];
        }

        $candidates = $required === [] ? array_keys($definitions) : $required;
        $configured = [];
        foreach ($candidates as $service) {
            $case = $definitions[$service];
            $ready = self::environmentPairConfigured($case[1], $case[2]);
            if ($required !== []) {
                $ready = $ready && self::environmentValueConfigured($case[3]);
            }
            if ($ready) {
                $configured[$service] = $case;

                continue;
            }
            if (in_array($service, $required, true)) {
                throw new \RuntimeException(sprintf(
                    'Required %s integration is unavailable: service configuration is missing.',
                    $service,
                ));
            }
        }

        return $configured;
    }

    /** @return list<string> */
    public static function requiredServices(): array
    {
        $manifest = getenv('INTEGRATION_SERVICES');
        if (!is_string($manifest) || trim($manifest) === '' || trim($manifest) === '[]') {
            return [];
        }

        try {
            $services = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw new \RuntimeException('INTEGRATION_SERVICES must be a JSON string list.', previous: $failure);
        }
        if (!is_array($services) || !array_is_list($services)) {
            throw new \RuntimeException('INTEGRATION_SERVICES must be a JSON string list.');
        }

        $normalized = [];
        foreach ($services as $service) {
            if (!is_string($service) || $service === '') {
                throw new \RuntimeException('INTEGRATION_SERVICES must contain non-empty service names.');
            }
            $normalized[$service] = true;
        }

        return array_keys($normalized);
    }

    /**
     * @param list<string>|null $availablePdoDrivers
     * @return list<string>
     */
    private static function databaseConfigurationIssues(
        string $driver,
        ?array $availablePdoDrivers,
        bool $strictCredentials,
    ): array {
        $issues = [];
        if (!self::databaseDriverAvailable($driver, $availablePdoDrivers)) {
            $issues[] = sprintf('PDO driver %s is missing', self::pdoDriver($driver));
        }

        $database = getenv('IC_SERVICE_DATABASE');
        $usernameName = $driver === 'mssql' ? 'IC_MSSQL_USER' : 'IC_SERVICE_USERNAME';
        if (!is_string($database) || $database === '') {
            $issues[] = 'IC_SERVICE_DATABASE is missing';
        }
        if (!self::environmentValueConfigured($usernameName)) {
            $issues[] = $usernameName . ' is missing';
        }
        if ($strictCredentials) {
            $passwordName = $driver === 'mssql' ? 'IC_MSSQL_PASSWORD' : 'IC_SERVICE_PASSWORD';
            if (!self::environmentValueConfigured($passwordName)) {
                $issues[] = $passwordName . ' is missing';
            }
        }

        return $issues;
    }

    /** @param list<string>|null $availablePdoDrivers */
    private static function databaseDriverAvailable(
        string $driver,
        ?array $availablePdoDrivers,
    ): bool {
        $availablePdoDrivers ??= \PDO::getAvailableDrivers();

        return in_array(self::pdoDriver($driver), $availablePdoDrivers, true);
    }

    private static function environmentPairConfigured(string $first, string $second): bool
    {
        return self::environmentValueConfigured($first)
            && self::environmentValueConfigured($second);
    }

    private static function environmentValueConfigured(string $name): bool
    {
        $value = getenv($name);

        return is_string($value) && $value !== '';
    }

    private static function pdoDriver(string $driver): string
    {
        return match ($driver) {
            'mysql', 'mariadb' => 'mysql',
            'pgsql' => 'pgsql',
            'mssql' => 'sqlsrv',
            default => $driver,
        };
    }

    /**
     * @param list<string> $drivers
     * @param list<string> $services
     * @return list<string>
     */
    private static function requiredDatabaseDrivers(array $drivers, array $services): array
    {
        if ($services === []) {
            return [];
        }

        $required = [];
        foreach (self::DATABASE_SERVICES as $service => $driver) {
            if (!in_array($service, $services, true)) {
                continue;
            }
            if (!in_array($driver, $drivers, true)) {
                throw new \RuntimeException(sprintf(
                    'Required %s integration has no database test dataset.',
                    $service,
                ));
            }
            $required[] = $driver;
        }

        return $required;
    }
}
