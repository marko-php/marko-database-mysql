<?php

declare(strict_types=1);

use Marko\Database\MySql\Tests\Fixtures\IntegrationDatabase;

it('reads the mysql driver test connection from MARKO_TEST_MYSQL_* variables', function (): void {
    $config = IntegrationDatabase::config([
        'MARKO_TEST_MYSQL_HOST' => 'db.test',
        'MARKO_TEST_MYSQL_PORT' => '53306',
        'MARKO_TEST_MYSQL_DATABASE' => 'drivers',
        'MARKO_TEST_MYSQL_USERNAME' => 'marko',
        'MARKO_TEST_MYSQL_PASSWORD' => 'secret',
    ]);

    expect($config?->host)->toBe('db.test')
        ->and($config?->port)->toBe(53306)
        ->and($config?->database)->toBe('drivers')
        ->and($config?->username)->toBe('marko')
        ->and($config?->password)->toBe('secret');
});

it('falls back to the documented defaults for unset mysql variables', function (): void {
    $config = IntegrationDatabase::config(['MARKO_TEST_MYSQL_HOST' => 'db.test']);

    expect($config?->port)->toBe(3306)
        ->and($config?->database)->toBe('marko_test')
        ->and($config?->username)->toBe('root')
        ->and($config?->password)->toBe('');
});

it('returns no mysql config when MARKO_TEST_MYSQL_HOST is unset', function (): void {
    expect(IntegrationDatabase::config([]))->toBeNull()
        ->and(IntegrationDatabase::config(['MARKO_TEST_MYSQL_HOST' => '']))->toBeNull();
});

it(
    'throws instead of skipping when MARKO_INTEGRATION_REQUIRED is set and MARKO_TEST_MYSQL_HOST is unset',
    function (): void {
        expect(fn () => IntegrationDatabase::config(['MARKO_INTEGRATION_REQUIRED' => '1']))
            ->toThrow(RuntimeException::class, 'MARKO_INTEGRATION_REQUIRED is set but MARKO_TEST_MYSQL_HOST is not');
    },
);
