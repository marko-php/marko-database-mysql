<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Integration;

use Marko\Database\Config\DatabaseConfig;
use Marko\Database\Exceptions\DeadlockException;
use Marko\Database\Exceptions\LockTimeoutException;
use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\MySql\Query\MySqlQueryBuilder;
use Marko\Database\MySql\Tests\Fixtures\SharedConnection\SharedConnectionContainer;
use RuntimeException;

/**
 * Deadlocks and lock timeouts against a real MySQL server. Uses the
 * MARKO_TEST_MYSQL_* variables and skips when they are unset. Creates and
 * drops the concurrency_items table.
 */
function mysqlConcurrencyConfig(): ?DatabaseConfig
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

/**
 * A second session in its own process (one PHP process can only wait on one
 * connection at a time). It runs $first in a transaction, then, once
 * released, waits for the lock $second needs. Returns once the contender is
 * blocked on that lock.
 *
 * @return array{process: resource, pipes: array<int, resource>}
 */
function mysqlStartContender(
    MySqlConnection $observer,
    string $first,
    string $second,
): array {
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/Fixtures/Concurrency/deadlock-contender.php', $first, $second],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the contender process');
    }

    $locked = trim((string) fgets($pipes[1]));

    if ($locked !== 'locked') {
        throw new RuntimeException('Contender failed: ' . $locked . stream_get_contents($pipes[2]));
    }

    fwrite($pipes[0], "go\n");
    fflush($pipes[0]);

    $deadline = microtime(true) + 10.0;

    while ((int) $observer->query(
        "SELECT COUNT(*) AS waiting FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'",
    )[0]['waiting'] === 0) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out waiting for the contender session to block');
        }

        usleep(20_000);
    }

    return ['process' => $process, 'pipes' => $pipes];
}

/**
 * Wait for the contender to finish and return what it printed: "committed",
 * or the class of the exception that stopped it.
 *
 * @param array{process: resource, pipes: array<int, resource>} $contender
 */
function mysqlFinishContender(
    array $contender,
): string {
    $output = trim((string) stream_get_contents($contender['pipes'][1]));
    $errors = (string) stream_get_contents($contender['pipes'][2]);

    foreach ($contender['pipes'] as $pipe) {
        fclose($pipe);
    }

    proc_close($contender['process']);

    if ($errors !== '') {
        throw new RuntimeException("Contender failed: $errors");
    }

    return $output;
}

pest()->group('integration');

beforeEach(function (): void {
    $config = mysqlConcurrencyConfig();

    if ($config === null) {
        $this->markTestSkipped(
            'Set MARKO_TEST_MYSQL_HOST (and _PORT, _DATABASE, _USERNAME, _PASSWORD) to run against a real MySQL server',
        );
    }

    $this->connection = new MySqlConnection($config);
    $this->observer = new MySqlConnection($config);
    $this->connection->execute('DROP TABLE IF EXISTS concurrency_items');
    $this->connection->execute('CREATE TABLE concurrency_items (id INT PRIMARY KEY, n INT NOT NULL) ENGINE=InnoDB');

    // Rows 3..40 let a contender modify far more rows than this session, so
    // InnoDB picks this session (the smaller transaction) as the deadlock victim.
    $rows = implode(', ', array_map(fn (int $id): string => "($id, 0)", range(1, 40)));
    $this->connection->execute("INSERT INTO concurrency_items (id, n) VALUES $rows");
});

afterEach(function (): void {
    if (isset($this->connection)) {
        $this->observer->reset();
        $this->observer->disconnect();
        $this->connection->reset();
        $this->connection->execute('DROP TABLE IF EXISTS concurrency_items');
        $this->connection->disconnect();
    }
});

describe('MySQL concurrency errors', function (): void {
    it('raises DeadlockException in one of two deadlocked sessions', function (): void {
        $this->connection->beginTransaction();
        $this->connection->execute('UPDATE concurrency_items SET n = n + 1 WHERE id = 1');
        $contender = mysqlStartContender(
            $this->observer,
            'UPDATE concurrency_items SET n = n + 1 WHERE id = 2',
            'UPDATE concurrency_items SET n = n + 1 WHERE id = 1',
        );

        try {
            $this->connection->execute('UPDATE concurrency_items SET n = n + 1 WHERE id = 2');
            $this->connection->commit();
            $mine = 'committed';
        } catch (DeadlockException) {
            $this->connection->rollback();
            $mine = DeadlockException::class;
        }

        expect([$mine, mysqlFinishContender($contender)])->toEqualCanonicalizing([
            DeadlockException::class,
            'committed',
        ])->and($this->connection->transactionLevel())->toBe(0);
    });

    it('raises LockTimeoutException when noWait meets a locked row', function (): void {
        $this->connection->beginTransaction();
        $this->connection->execute('UPDATE concurrency_items SET n = 1 WHERE id = 1');

        $this->observer->beginTransaction();
        $contend = fn () => new MySqlQueryBuilder($this->observer)
            ->table('concurrency_items')
            ->where('id', '=', 1)
            ->lockForUpdate()
            ->noWait()
            ->get();

        expect($contend)->toThrow(LockTimeoutException::class, 'NOWAIT is set');

        $this->observer->rollback();
        $this->connection->rollback();
    });

    it('raises LockTimeoutException when the lock wait timeout expires', function (): void {
        $this->connection->beginTransaction();
        $this->connection->execute('UPDATE concurrency_items SET n = 1 WHERE id = 1');

        $this->observer->execute('SET SESSION innodb_lock_wait_timeout = 1');
        $this->observer->beginTransaction();

        expect(fn () => $this->observer->execute('UPDATE concurrency_items SET n = 2 WHERE id = 1'))
            ->toThrow(LockTimeoutException::class, 'Lock wait timeout exceeded');

        $this->observer->rollback();
        $this->connection->rollback();
    });

    it('surfaces the deadlock from a nested transaction and retries at the outermost level', function (): void {
        $attempts = 0;
        $contenderOutcome = null;

        $this->connection->transaction(function () use (&$attempts, &$contenderOutcome): void {
            $attempts++;
            $attempt = $attempts;

            $this->connection->transaction(function () use ($attempt, &$contenderOutcome): void {
                $this->connection->execute('UPDATE concurrency_items SET n = n + 1 WHERE id = 1');

                if ($attempt === 1) {
                    // The contender updates rows 2..40, so this session is the
                    // smaller transaction and InnoDB rolls it back.
                    $contender = mysqlStartContender(
                        $this->observer,
                        'UPDATE concurrency_items SET n = n + 1 WHERE id >= 2',
                        'UPDATE concurrency_items SET n = n + 1 WHERE id = 1',
                    );

                    try {
                        $this->connection->execute('UPDATE concurrency_items SET n = n + 1 WHERE id = 2');
                    } finally {
                        $contenderOutcome = mysqlFinishContender($contender);
                    }
                }
            });
        }, attempts: 2);

        $rows = $this->observer->query('SELECT id, n FROM concurrency_items WHERE id IN (1, 2) ORDER BY id');

        expect($attempts)->toBe(2)
            ->and($contenderOutcome)->toBe('committed')
            ->and(array_column($rows, 'n'))->toBe([2, 1])
            ->and($this->connection->transactionLevel())->toBe(0);
    });
});
