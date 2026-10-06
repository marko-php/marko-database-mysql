<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Fixtures;

use Marko\Database\Config\DatabaseConfig;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\MySql\Tests\Fixtures\SharedConnection\SharedConnectionContainer;
use RuntimeException;

/**
 * Connection settings for the tests that run against a real MySQL
 * server (the integration-services group), read from the MARKO_TEST_MYSQL_*
 * variables.
 *
 * Without MARKO_TEST_MYSQL_HOST the tests skip. With MARKO_INTEGRATION_REQUIRED
 * set (the CI Integration job) a missing host is a failure instead, so the
 * job can never pass by skipping these tests.
 */
class IntegrationDatabase
{
    public const string SKIP_REASON = 'Set MARKO_TEST_MYSQL_HOST (and _PORT, _DATABASE, _USERNAME, _PASSWORD) to run '
        . 'against a real MySQL server. `docker compose -f tests/Integration/compose.yml up -d` starts one; '
        . 'see .claude/testing.md.';

    /**
     * The configured server, or null when MARKO_TEST_MYSQL_HOST is unset.
     *
     * @param array<string, string>|null $env The variables to read; the real process environment when null
     * @throws RuntimeException When MARKO_INTEGRATION_REQUIRED is set and MARKO_TEST_MYSQL_HOST is unset
     */
    public static function config(
        ?array $env = null,
    ): ?DatabaseConfig {
        $env ??= getenv();
        $host = $env['MARKO_TEST_MYSQL_HOST'] ?? '';

        if ($host === '') {
            if (in_array(strtolower($env['MARKO_INTEGRATION_REQUIRED'] ?? ''), ['1', 'true', 'yes'], true)) {
                throw new RuntimeException(
                    'MARKO_INTEGRATION_REQUIRED is set but MARKO_TEST_MYSQL_HOST is not, so the MySQL driver '
                    . 'integration tests cannot run. ' . self::SKIP_REASON,
                );
            }

            return null;
        }

        return SharedConnectionContainer::config(
            host: $host,
            port: (int) self::value($env, 'MARKO_TEST_MYSQL_PORT', '3306'),
            database: self::value($env, 'MARKO_TEST_MYSQL_DATABASE', 'marko_test'),
            username: self::value($env, 'MARKO_TEST_MYSQL_USERNAME', 'root'),
            password: $env['MARKO_TEST_MYSQL_PASSWORD'] ?? '',
        );
    }

    /**
     * Whether the connected server is MariaDB, for the tests whose expectations differ between MySQL and
     * MariaDB. The server is also checked against MARKO_TEST_MYSQL_SERVER (see assertServer()).
     *
     * @throws RuntimeException When MARKO_TEST_MYSQL_SERVER is unknown or names a different server
     */
    public static function isMariaDb(
        ConnectionInterface $connection,
    ): bool {
        $version = self::serverVersion($connection);
        self::assertServer($version);

        return self::isMariaDbVersion($version);
    }

    /**
     * The version the server reports, such as `8.4.3` or `11.8.7-MariaDB-ubu2404`.
     */
    public static function serverVersion(
        ConnectionInterface $connection,
    ): string {
        return (string) ($connection->query('SELECT VERSION() AS version')[0]['version'] ?? '');
    }

    /**
     * Whether $version is a MariaDB version string.
     */
    public static function isMariaDbVersion(
        string $version,
    ): bool {
        return str_contains(strtolower($version), 'mariadb');
    }

    /**
     * MARKO_TEST_MYSQL_SERVER (`mysql` or `mariadb`) declares the server a run must reach. The CI job runs the
     * suite once per server, so a run pointed at the wrong port fails instead of silently skipping the
     * MariaDB-only tests. Unset, nothing is checked.
     *
     * @param array<string, string>|null $env The variables to read; the real process environment when null
     * @throws RuntimeException When MARKO_TEST_MYSQL_SERVER is unknown or names a different server
     */
    public static function assertServer(
        string $version,
        ?array $env = null,
    ): void {
        $env ??= getenv();
        $isMariaDb = self::isMariaDbVersion($version);
        $expected = strtolower($env['MARKO_TEST_MYSQL_SERVER'] ?? '');

        if ($expected === '') {
            return;
        }

        if (!in_array($expected, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException(
                "MARKO_TEST_MYSQL_SERVER must be mysql or mariadb, got \"{$env['MARKO_TEST_MYSQL_SERVER']}\".",
            );
        }

        if (($expected === 'mariadb') !== $isMariaDb) {
            throw new RuntimeException(
                "MARKO_TEST_MYSQL_SERVER is $expected but the server reports version $version. Check "
                . 'MARKO_TEST_MYSQL_HOST and MARKO_TEST_MYSQL_PORT.',
            );
        }
    }

    /**
     * @param array<string, string> $env
     */
    private static function value(
        array $env,
        string $name,
        string $default,
    ): string {
        return ($env[$name] ?? '') !== '' ? $env[$name] : $default;
    }
}
