<?php

declare(strict_types=1);

use Infocyph\Omnibus\Tests\Fixtures\IntegrationEnvironment;

/**
 * @param array<string,string|null> $environment
 */
function omnibusWithIntegrationEnvironment(array $environment, Closure $callback): mixed
{
    $previous = [];
    foreach ($environment as $name => $value) {
        $previous[$name] = getenv($name);
        if ($value === null) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }

    try {
        return $callback();
    } finally {
        foreach ($previous as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
    }
}

test('integration service discovery remains optional without a manifest', function (): void {
    omnibusWithIntegrationEnvironment([
        'INTEGRATION_SERVICES' => null,
        'IC_SERVICE_DATABASE' => null,
        'IC_SERVICE_USERNAME' => null,
    ], function (): void {
        expect(IntegrationEnvironment::requiredServices())->toBe([])
            ->and(IntegrationEnvironment::configuredDatabaseDrivers(['pgsql'], ['pgsql']))->toBe([]);
    });
});

test('integration service manifest must be a JSON string list', function (): void {
    omnibusWithIntegrationEnvironment([
        'INTEGRATION_SERVICES' => '{"postgres":true}',
    ], function (): void {
        expect(fn(): array => IntegrationEnvironment::requiredServices())
            ->toThrow(RuntimeException::class, 'JSON string list');
    });
});

test('required database service fails when its PDO driver is missing', function (): void {
    omnibusWithIntegrationEnvironment([
        'INTEGRATION_SERVICES' => '["postgres"]',
        'IC_SERVICE_DATABASE' => 'phpforge',
        'IC_SERVICE_USERNAME' => 'phpforge',
        'IC_SERVICE_PASSWORD' => 'secret',
    ], function (): void {
        expect(fn(): array => IntegrationEnvironment::configuredDatabaseDrivers(['pgsql'], []))
            ->toThrow(RuntimeException::class, 'PDO driver pgsql is missing');
    });
});

test('required MSSQL service fails before discovery when credentials are missing', function (): void {
    omnibusWithIntegrationEnvironment([
        'INTEGRATION_SERVICES' => '["mssql"]',
        'IC_SERVICE_DATABASE' => 'phpforge',
        'IC_MSSQL_USER' => null,
        'IC_MSSQL_PASSWORD' => 'secret',
    ], function (): void {
        expect(fn(): array => IntegrationEnvironment::configuredDatabaseDrivers(['mssql'], ['sqlsrv']))
            ->toThrow(RuntimeException::class, 'IC_MSSQL_USER is missing');
    });
});

test('required database manifest produces one case for every selected SQL service', function (): void {
    omnibusWithIntegrationEnvironment([
        'INTEGRATION_SERVICES' => '["mysql","mariadb","postgres"]',
        'IC_SERVICE_DATABASE' => 'phpforge',
        'IC_SERVICE_USERNAME' => 'phpforge',
        'IC_SERVICE_PASSWORD' => 'secret',
    ], function (): void {
        expect(IntegrationEnvironment::configuredDatabaseDrivers(
            ['mysql', 'mariadb', 'pgsql', 'mssql'],
            ['mysql', 'pgsql'],
        ))->toBe(['mysql', 'mariadb', 'pgsql']);
    });
});

test('narrow database manifest does not register unselected configured services', function (): void {
    omnibusWithIntegrationEnvironment([
        'INTEGRATION_SERVICES' => '["postgres"]',
        'IC_SERVICE_DATABASE' => 'phpforge',
        'IC_SERVICE_USERNAME' => 'phpforge',
        'IC_SERVICE_PASSWORD' => 'secret',
        'IC_MSSQL_USER' => 'sa',
        'IC_MSSQL_PASSWORD' => 'secret',
    ], function (): void {
        expect(IntegrationEnvironment::configuredDatabaseDrivers(
            ['mysql', 'mariadb', 'pgsql', 'mssql'],
            ['mysql', 'pgsql', 'sqlsrv'],
        ))->toBe(['pgsql']);
    });
});

test('required database service fails when its dataset is absent', function (): void {
    omnibusWithIntegrationEnvironment([
        'INTEGRATION_SERVICES' => '["mssql"]',
    ], function (): void {
        expect(fn(): array => IntegrationEnvironment::configuredDatabaseDrivers(['pgsql'], ['pgsql']))
            ->toThrow(RuntimeException::class, 'no database test dataset');
    });
});
