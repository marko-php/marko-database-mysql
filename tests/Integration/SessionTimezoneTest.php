<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Marko\Database\Config\DatabaseConfig;
use Marko\Database\Config\DatabaseTimezoneConfig;
use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\MySql\Tests\Fixtures\IntegrationDatabase;

/*
 * The session time zone against a real MySQL or MariaDB server whose own zone
 * is not UTC. Set MARKO_TEST_MYSQL_HOST (and optionally MARKO_TEST_MYSQL_PORT,
 * _DATABASE, _USERNAME, _PASSWORD) to enable; the tests skip otherwise.
 *
 * Each test sets the server's global time_zone to America/New_York (the zone
 * new sessions start in) and restores it afterwards, so the user needs the
 * SYSTEM_VARIABLES_ADMIN (or SUPER) privilege, which root has. The named zones
 * need the server's time zone tables, which the official images load. The
 * tests create and drop the session_timezone_items table.
 *
 * Settings come from tests/Fixtures/IntegrationDatabase. With
 * MARKO_INTEGRATION_REQUIRED set (CI), a missing host fails instead of
 * skipping. Part of the integration-services group.
 */

pest()->group('integration-services');

/**
 * The integration database config with database.timezone set to the given zone.
 */
function mysqlConfigInTimezone(
    DatabaseConfig $config,
    string $timezone,
): DatabaseConfig {
    return DatabaseConfig::fromArray([
        'driver' => $config->driver,
        'host' => $config->host,
        'port' => $config->port,
        'database' => $config->database,
        'username' => $config->username,
        'password' => $config->password,
        'timezone' => $timezone,
    ]);
}

/**
 * Seconds between a stored datetime, read in the given zone, and now.
 */
function mysqlSecondsFromNow(
    string $stored,
    string $timezone,
): int {
    $instant = DatabaseTimezoneConfig::fromName($timezone)->parse($stored);

    return abs($instant->getTimestamp() - time());
}

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->config = $config;
    $this->admin = new MySqlConnection($config);
    $this->serverZone = $this->admin->query('SELECT @@GLOBAL.time_zone AS zone')[0]['zone'];
    $this->admin->execute("SET GLOBAL time_zone = 'America/New_York'");
    $this->admin->execute('DROP TABLE IF EXISTS session_timezone_items');
    $this->admin->execute(
        'CREATE TABLE session_timezone_items ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY, '
        . 'stamped_at TIMESTAMP NULL, '
        . 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
        . 'created_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
    );
});

afterEach(function (): void {
    if (isset($this->admin)) {
        $this->admin->execute('DROP TABLE IF EXISTS session_timezone_items');
        $this->admin->execute('SET GLOBAL time_zone = ?', [$this->serverZone]);
        $this->admin->disconnect();
    }
});

describe('MySQL session time zone', function (): void {
    it('runs the session in database.timezone although the server zone is not UTC', function (): void {
        $connection = new MySqlConnection($this->config);
        $zones = $connection->query('SELECT @@GLOBAL.time_zone AS server, @@SESSION.time_zone AS session')[0];

        expect($zones['server'])->toBe('America/New_York')
            ->and($zones['session'])->toBe('+00:00');

        $connection->disconnect();
    })->issue(304);

    it('stores a TIMESTAMP value in the DST gap of the server zone unchanged', function (): void {
        // 02:30 on 8 March 2026 does not exist in New York (clocks jump from 02:00 to 03:00). A session in the
        // server zone shifts it; a UTC session stores and returns it as written.
        $connection = new MySqlConnection($this->config);
        $connection->execute(
            'INSERT INTO session_timezone_items (stamped_at) VALUES (?)',
            ['2026-03-08 02:30:00'],
        );
        $row = $connection->query(
            'SELECT stamped_at, UNIX_TIMESTAMP(stamped_at) AS epoch FROM session_timezone_items',
        )[0];

        expect($row['stamped_at'])->toBe('2026-03-08 02:30:00')
            ->and((int) $row['epoch'])->toBe(
                new DateTimeImmutable('2026-03-08 02:30:00', new DateTimeZone('UTC'))->getTimestamp(),
            );

        $connection->disconnect();
    })->issue(304);

    it('fills DEFAULT CURRENT_TIMESTAMP with the current time in database.timezone', function (): void {
        $connection = new MySqlConnection($this->config);
        $connection->execute('INSERT INTO session_timezone_items () VALUES ()');
        $row = $connection->query('SELECT created_at, created_on FROM session_timezone_items')[0];

        expect(mysqlSecondsFromNow($row['created_at'], 'UTC'))->toBeLessThan(60)
            ->and(mysqlSecondsFromNow($row['created_on'], 'UTC'))->toBeLessThan(60);

        $connection->disconnect();
    })->issue(304);

    it('pins a named database timezone on the session', function (): void {
        $connection = new MySqlConnection(mysqlConfigInTimezone($this->config, 'Asia/Tokyo'));
        $connection->execute('INSERT INTO session_timezone_items () VALUES ()');
        $session = $connection->query('SELECT @@SESSION.time_zone AS zone')[0]['zone'];
        $row = $connection->query('SELECT created_at, created_on FROM session_timezone_items')[0];

        expect($session)->toBe('Asia/Tokyo')
            ->and(mysqlSecondsFromNow($row['created_at'], 'Asia/Tokyo'))->toBeLessThan(60)
            ->and(mysqlSecondsFromNow($row['created_on'], 'Asia/Tokyo'))->toBeLessThan(60);

        $connection->disconnect();
    })->issue(304);

    it('pins a fixed offset database timezone without the time zone tables', function (): void {
        $connection = new MySqlConnection(mysqlConfigInTimezone($this->config, '+05:30'));
        $session = $connection->query('SELECT @@SESSION.time_zone AS zone')[0]['zone'];

        expect($session)->toBe('+05:30');

        $connection->disconnect();
    })->issue(304);

    it('pins the session zone again after a reconnect', function (): void {
        $connection = new MySqlConnection($this->config);
        $connection->connect();
        $connection->disconnect();
        $session = $connection->query('SELECT @@SESSION.time_zone AS zone')[0]['zone'];

        expect($session)->toBe('+00:00');

        $connection->disconnect();
    })->issue(304);
});
