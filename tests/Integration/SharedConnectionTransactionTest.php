<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Integration;

use Marko\Database\Config\DatabaseConfig;
use Marko\Database\Connection\TransactionInterface;
use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\MySql\Tests\Fixtures\SharedConnection\Account;
use Marko\Database\MySql\Tests\Fixtures\SharedConnection\AccountRepository;
use Marko\Database\MySql\Tests\Fixtures\SharedConnection\AuditEntry;
use Marko\Database\MySql\Tests\Fixtures\SharedConnection\AuditEntryRepository;
use Marko\Database\MySql\Tests\Fixtures\SharedConnection\SharedConnectionContainer;
use RuntimeException;

/**
 * Runs against a real MySQL server. Set MARKO_TEST_MYSQL_HOST (and
 * optionally MARKO_TEST_MYSQL_PORT, _DATABASE, _USERNAME, _PASSWORD) to
 * enable; the tests skip otherwise. The tests create and drop the
 * shared_accounts and shared_audit_entries tables.
 */
function mysqlIntegrationConfig(): ?DatabaseConfig
{
    $host = getenv('MARKO_TEST_MYSQL_HOST');

    if ($host === false || $host === '') {
        return null;
    }

    return SharedConnectionContainer::config(
        host: $host,
        port: (int) (getenv('MARKO_TEST_MYSQL_PORT') ?: 3306),
        database: getenv('MARKO_TEST_MYSQL_DATABASE') ?: 'marko_test',
        username: getenv('MARKO_TEST_MYSQL_USERNAME') ?: 'root',
        password: getenv('MARKO_TEST_MYSQL_PASSWORD') ?: '',
    );
}

function mysqlRowCount(MySqlConnection $connection, string $table): int
{
    return (int) $connection->query("SELECT COUNT(*) AS total FROM $table")[0]['total'];
}

const MYSQL_SKIP_REASON = 'Set MARKO_TEST_MYSQL_HOST (and _PORT, _DATABASE, _USERNAME, _PASSWORD) to run against a real MySQL server';

beforeEach(function (): void {
    $config = mysqlIntegrationConfig();

    if ($config === null) {
        return;
    }

    $this->observer = new MySqlConnection($config);
    $this->observer->execute('DROP TABLE IF EXISTS shared_accounts, shared_audit_entries');
    $this->observer->execute('CREATE TABLE shared_accounts (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL)');
    $this->observer->execute('CREATE TABLE shared_audit_entries (id INT AUTO_INCREMENT PRIMARY KEY, message VARCHAR(255) NOT NULL)');
});

afterEach(function (): void {
    if (isset($this->observer)) {
        $this->observer->execute('DROP TABLE IF EXISTS shared_accounts, shared_audit_entries');
        $this->observer->disconnect();
    }
});

describe('MySQL transactions across repositories', function (): void {
    it('rolls back writes from two repositories when the transaction callback throws', function (): void {
        $container = SharedConnectionContainer::build(mysqlIntegrationConfig());
        $accounts = $container->get(AccountRepository::class);
        $auditEntries = $container->get(AuditEntryRepository::class);

        $run = fn () => $container->get(TransactionInterface::class)->transaction(
            function () use ($accounts, $auditEntries): void {
                $account = new Account();
                $account->name = 'Ada';
                $accounts->save($account);

                $entry = new AuditEntry();
                $entry->message = 'Account created';
                $auditEntries->save($entry);

                throw new RuntimeException('Payment declined');
            },
        );

        expect($run)->toThrow(RuntimeException::class, 'Payment declined')
            ->and(mysqlRowCount($this->observer, 'shared_accounts'))->toBe(0)
            ->and(mysqlRowCount($this->observer, 'shared_audit_entries'))->toBe(0);
    })->skip(fn (): bool => mysqlIntegrationConfig() === null, MYSQL_SKIP_REASON)->group('integration');

    it('commits writes from two repositories when the transaction callback succeeds', function (): void {
        $container = SharedConnectionContainer::build(mysqlIntegrationConfig());
        $accounts = $container->get(AccountRepository::class);
        $auditEntries = $container->get(AuditEntryRepository::class);

        $container->get(TransactionInterface::class)->transaction(
            function () use ($accounts, $auditEntries): void {
                $account = new Account();
                $account->name = 'Ada';
                $accounts->save($account);

                $entry = new AuditEntry();
                $entry->message = 'Account created';
                $auditEntries->save($entry);
            },
        );

        expect(mysqlRowCount($this->observer, 'shared_accounts'))->toBe(1)
            ->and(mysqlRowCount($this->observer, 'shared_audit_entries'))->toBe(1);
    })->skip(fn (): bool => mysqlIntegrationConfig() === null, MYSQL_SKIP_REASON)->group('integration');
});
