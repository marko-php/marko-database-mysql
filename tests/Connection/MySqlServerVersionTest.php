<?php

declare(strict_types=1);

use Marko\Database\MySql\Connection\MySqlServerVersion;
use Marko\Database\MySql\Exceptions\ServerVersionException;

describe('MySqlServerVersion', function (): void {
    it('parses a MySQL version string', function (): void {
        $version = MySqlServerVersion::fromString('8.4.3');

        expect($version->isMariaDb)->toBeFalse()
            ->and($version->version)->toBe('8.4.3')
            ->and($version->reported)->toBe('8.4.3');
    });

    it('parses a MySQL version string with a suffix', function (): void {
        $version = MySqlServerVersion::fromString('8.0.36-0ubuntu0.22.04.1-log');

        expect($version->isMariaDb)->toBeFalse()
            ->and($version->version)->toBe('8.0.36');
    });

    it('parses a MariaDB 11 version string with a distribution suffix', function (): void {
        $version = MySqlServerVersion::fromString('11.8.7-MariaDB-ubu2404');

        expect($version->isMariaDb)->toBeTrue()
            ->and($version->version)->toBe('11.8.7')
            ->and($version->reported)->toBe('11.8.7-MariaDB-ubu2404');
    });

    it('strips the 5.5.5- prefix MariaDB before 11.0 reports over the protocol', function (): void {
        $version = MySqlServerVersion::fromString('5.5.5-10.11.8-MariaDB-1:10.11.8+maria~ubu2204');

        expect($version->isMariaDb)->toBeTrue()
            ->and($version->version)->toBe('10.11.8');
    });

    it('detects MariaDB case-insensitively', function (): void {
        expect(MySqlServerVersion::fromString('10.6.5-mariadb-log')->isMariaDb)->toBeTrue();
    });

    it('compares the version with isAtLeast', function (): void {
        $version = MySqlServerVersion::fromString('5.5.5-10.11.8-MariaDB');

        expect($version->isAtLeast('10.5'))->toBeTrue()
            ->and($version->isAtLeast('10.11.8'))->toBeTrue()
            ->and($version->isAtLeast('10.11.9'))->toBeFalse()
            ->and($version->isAtLeast('11.0'))->toBeFalse();
    });

    it('throws when the version string has no version number', function (string $reported): void {
        expect(fn () => MySqlServerVersion::fromString($reported))
            ->toThrow(ServerVersionException::class, 'Could not read the MySQL server version');
    })->with(['', 'MariaDB', 'unknown']);
});
