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

it('reports MariaDB when the server version names MariaDB', function (): void {
    expect(IntegrationDatabase::isMariaDbVersion('11.8.7-MariaDB-ubu2404'))->toBeTrue()
        ->and(IntegrationDatabase::isMariaDbVersion('5.5.5-10.11.6-MariaDB'))->toBeTrue();
});

it('reports MySQL when the server version does not name MariaDB', function (): void {
    expect(IntegrationDatabase::isMariaDbVersion('8.4.3'))->toBeFalse();
});

it('accepts the server MARKO_TEST_MYSQL_SERVER names', function (): void {
    expect(fn () => IntegrationDatabase::assertServer('8.4.3', ['MARKO_TEST_MYSQL_SERVER' => 'mysql']))
        ->not->toThrow(RuntimeException::class)
        ->and(fn () => IntegrationDatabase::assertServer('11.8.7-MariaDB', ['MARKO_TEST_MYSQL_SERVER' => 'MariaDB']))
        ->not->toThrow(RuntimeException::class);
});

it('fails when MARKO_TEST_MYSQL_SERVER names a different server than the one connected', function (): void {
    expect(fn () => IntegrationDatabase::assertServer('8.4.3', ['MARKO_TEST_MYSQL_SERVER' => 'mariadb']))
        ->toThrow(RuntimeException::class, 'MARKO_TEST_MYSQL_SERVER is mariadb but the server reports version 8.4.3')
        ->and(fn () => IntegrationDatabase::assertServer('11.8.7-MariaDB', ['MARKO_TEST_MYSQL_SERVER' => 'mysql']))
        ->toThrow(
            RuntimeException::class,
            'MARKO_TEST_MYSQL_SERVER is mysql but the server reports version 11.8.7-MariaDB',
        );
});

it('rejects an unknown MARKO_TEST_MYSQL_SERVER value', function (): void {
    expect(fn () => IntegrationDatabase::assertServer('8.4.3', ['MARKO_TEST_MYSQL_SERVER' => 'percona']))
        ->toThrow(RuntimeException::class, 'MARKO_TEST_MYSQL_SERVER must be mysql or mariadb, got "percona"');
});

it('does not check the server when MARKO_TEST_MYSQL_SERVER is unset', function (): void {
    expect(fn () => IntegrationDatabase::assertServer('8.4.3', []))
        ->not->toThrow(RuntimeException::class)
        ->and(fn () => IntegrationDatabase::assertServer('11.8.7-MariaDB', ['MARKO_TEST_MYSQL_SERVER' => '']))
        ->not->toThrow(RuntimeException::class);
});
