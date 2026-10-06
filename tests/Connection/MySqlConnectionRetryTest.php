<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Connection;

use Marko\Database\Config\DatabaseConfig;
use Marko\Database\Exceptions\DeadlockException;
use Marko\Database\Exceptions\QueryException;
use Marko\Database\Exceptions\TransactionConflictException;
use Marko\Database\Exceptions\TransactionException;
use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\MySql\Tests\Fixtures\Retry\ScriptedPdo;
use PDO;
use PDOException;
use RuntimeException;

function makeRetryMySqlConnection(
    ScriptedPdo $pdo,
): MySqlConnection {
    $config = DatabaseConfig::fromArray([
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'test',
        'username' => 'test',
        'password' => 'test',
    ]);

    return new class ($config, $pdo) extends MySqlConnection
    {
        public function __construct(
            DatabaseConfig $config,
            private readonly ScriptedPdo $scriptedPdo,
        ) {
            parent::__construct($config);
        }

        protected function createPdo(
            string $dsn,
            string $username,
            string $password,
            array $options,
        ): PDO {
            return $this->scriptedPdo;
        }
    };
}

function mysqlDeadlock(): DeadlockException
{
    return DeadlockException::fromDriverError(
        new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock'),
        'UPDATE items SET name = ?',
        [],
    );
}

function mysqlCommitFailure(
    string $sqlState,
    int $driverCode,
    string $serverMessage,
): PDOException {
    $exception = new PDOException("SQLSTATE[$sqlState]: Error: $driverCode $serverMessage");
    $exception->errorInfo = [$sqlState, $driverCode, $serverMessage];

    return $exception;
}

describe('MySqlConnection::transaction() retries', function (): void {
    it('retries the outermost transaction on a conflict and returns the successful result', function (): void {
        $pdo = new ScriptedPdo();
        $connection = makeRetryMySqlConnection($pdo);
        $calls = 0;

        $result = $connection->transaction(function () use ($connection, &$calls): string {
            $calls++;
            $connection->execute('INSERT INTO items (name) VALUES (?)', ["attempt $calls"]);

            if ($calls < 3) {
                throw mysqlDeadlock();
            }

            return 'done';
        }, attempts: 3);

        expect($result)->toBe('done')
            ->and($calls)->toBe(3)
            ->and($pdo->statements)->toBe(['BEGIN', 'ROLLBACK', 'BEGIN', 'ROLLBACK', 'BEGIN', 'COMMIT'])
            ->and(array_column($connection->query('SELECT name FROM items'), 'name'))->toBe(['attempt 3']);
    });

    it('gives up after the given number of attempts and rethrows the last conflict', function (): void {
        $connection = makeRetryMySqlConnection(new ScriptedPdo());
        $thrown = [];
        $caught = null;

        try {
            $connection->transaction(function () use (&$thrown): void {
                $thrown[] = $conflict = mysqlDeadlock();

                throw $conflict;
            }, attempts: 3);
        } catch (DeadlockException $e) {
            $caught = $e;
        }

        expect($thrown)->toHaveCount(3)
            ->and($caught)->toBe($thrown[2])
            ->and($connection->transactionLevel())->toBe(0);
    });

    it('does not retry on exceptions that are not transaction conflicts', function (): void {
        $connection = makeRetryMySqlConnection(new ScriptedPdo());
        $calls = 0;

        $run = function () use ($connection, &$calls): void {
            $connection->transaction(function () use (&$calls): void {
                $calls++;

                throw new RuntimeException('Not a conflict');
            }, attempts: 3);
        };

        expect($run)->toThrow(RuntimeException::class, 'Not a conflict')
            ->and($calls)->toBe(1);
    });

    it('does not retry when attempts is one', function (): void {
        $connection = makeRetryMySqlConnection(new ScriptedPdo());
        $calls = 0;

        $run = function () use ($connection, &$calls): void {
            $connection->transaction(function () use (&$calls): void {
                $calls++;

                throw mysqlDeadlock();
            });
        };

        expect($run)->toThrow(DeadlockException::class)
            ->and($calls)->toBe(1);
    });

    it('discards after-commit callbacks registered in a failed attempt', function (): void {
        $connection = makeRetryMySqlConnection(new ScriptedPdo());
        $calls = 0;
        $log = [];

        $connection->transaction(function () use ($connection, &$calls, &$log): void {
            $calls++;
            $attempt = $calls;
            $connection->afterCommit(function () use (&$log, $attempt): void {
                $log[] = "committed attempt $attempt";
            });

            if ($attempt === 1) {
                throw mysqlDeadlock();
            }
        }, attempts: 2);

        expect($log)->toBe(['committed attempt 2']);
    });

    it('runs the after-rollback callbacks of each failed attempt', function (): void {
        $connection = makeRetryMySqlConnection(new ScriptedPdo());
        $calls = 0;
        $log = [];

        $connection->transaction(function () use ($connection, &$calls, &$log): void {
            $calls++;
            $attempt = $calls;
            $connection->afterRollback(function () use (&$log, $attempt): void {
                $log[] = "rolled back attempt $attempt";
            });

            if ($attempt < 3) {
                throw mysqlDeadlock();
            }
        }, attempts: 3);

        expect($log)->toBe(['rolled back attempt 1', 'rolled back attempt 2']);
    });

    it('starts each retry from transaction level zero', function (): void {
        $connection = makeRetryMySqlConnection(new ScriptedPdo());
        $levels = [];

        $connection->transaction(function () use ($connection, &$levels): void {
            $levels[] = $connection->transactionLevel();

            if (count($levels) < 3) {
                throw mysqlDeadlock();
            }
        }, attempts: 3);

        expect($levels)->toBe([1, 1, 1])
            ->and($connection->transactionLevel())->toBe(0);
    });

    it('never retries a nested transaction by itself', function (): void {
        $pdo = new ScriptedPdo();
        $connection = makeRetryMySqlConnection($pdo);
        $innerCalls = 0;

        $run = function () use ($connection, &$innerCalls): void {
            $connection->transaction(function () use ($connection, &$innerCalls): void {
                $connection->transaction(function () use (&$innerCalls): void {
                    $innerCalls++;

                    throw mysqlDeadlock();
                }, attempts: 3);
            });
        };

        expect($run)->toThrow(DeadlockException::class)
            ->and($innerCalls)->toBe(1)
            ->and($pdo->statements)->toBe([
                'BEGIN',
                'SAVEPOINT marko_sp_1',
                'ROLLBACK TO SAVEPOINT marko_sp_1',
                'ROLLBACK',
            ]);
    });

    it('retries the outermost transaction when a nested transaction hits a conflict', function (): void {
        $connection = makeRetryMySqlConnection(new ScriptedPdo());
        $outerCalls = 0;
        $innerCalls = 0;

        $connection->transaction(function () use ($connection, &$outerCalls, &$innerCalls): void {
            $outerCalls++;
            $connection->transaction(function () use (&$innerCalls): void {
                $innerCalls++;

                if ($innerCalls === 1) {
                    throw mysqlDeadlock();
                }
            });
        }, attempts: 2);

        expect($outerCalls)->toBe(2)
            ->and($innerCalls)->toBe(2);
    });

    it('surfaces the conflict when the savepoint rollback after it fails', function (): void {
        $pdo = new ScriptedPdo();
        $pdo->execFailures['ROLLBACK TO SAVEPOINT'] = new PDOException('SAVEPOINT marko_sp_1 does not exist');
        $connection = makeRetryMySqlConnection($pdo);
        $outerCalls = 0;

        $connection->transaction(function () use ($connection, &$outerCalls): void {
            $outerCalls++;
            $connection->transaction(function () use ($outerCalls): void {
                if ($outerCalls === 1) {
                    throw mysqlDeadlock();
                }
            });
        }, attempts: 2);

        expect($outerCalls)->toBe(2)
            ->and($connection->transactionLevel())->toBe(0);
    });

    it('retries a deadlock after the server already ended the outermost transaction', function (): void {
        $pdo = new ScriptedPdo();
        $connection = makeRetryMySqlConnection($pdo);
        $calls = 0;
        $log = [];

        $result = $connection->transaction(function () use ($connection, $pdo, &$calls, &$log): int {
            $calls++;
            $attempt = $calls;
            $connection->afterRollback(function () use (&$log, $attempt): void {
                $log[] = "rolled back attempt $attempt";
            });

            if ($attempt === 1) {
                $pdo->endTransactionOnServer();

                throw mysqlDeadlock();
            }

            return $attempt;
        }, attempts: 2);

        expect($result)->toBe(2)
            ->and($log)->toBe(['rolled back attempt 1'])
            ->and($pdo->statements)->toBe(['BEGIN', 'BEGIN', 'COMMIT'])
            ->and($connection->transactionLevel())->toBe(0);
    });

    it('surfaces the deadlock from a nested transaction and retries at the outermost level', function (): void {
        $pdo = new ScriptedPdo();
        $connection = makeRetryMySqlConnection($pdo);
        $outerCalls = 0;
        $log = [];

        $connection->transaction(function () use ($connection, $pdo, &$outerCalls, &$log): void {
            $outerCalls++;
            $attempt = $outerCalls;
            $connection->transaction(function () use ($connection, $pdo, &$log, $attempt): void {
                $connection->afterRollback(function () use (&$log, $attempt): void {
                    $log[] = "inner rolled back attempt $attempt";
                });

                if ($attempt === 1) {
                    $pdo->endTransactionOnServer();

                    throw mysqlDeadlock();
                }
            });
        }, attempts: 2);

        expect($outerCalls)->toBe(2)
            ->and($log)->toBe(['inner rolled back attempt 1'])
            ->and($pdo->statements)->toBe([
                'BEGIN',
                'SAVEPOINT marko_sp_1',
                'BEGIN',
                'SAVEPOINT marko_sp_1',
                'RELEASE SAVEPOINT marko_sp_1',
                'COMMIT',
            ]);
    });

    it('retries when the COMMIT statement reports a conflict', function (): void {
        $pdo = new ScriptedPdo();
        $pdo->commitFailures[] = mysqlCommitFailure(
            '40001',
            1213,
            'Deadlock found when trying to get lock; try restarting transaction',
        );
        $connection = makeRetryMySqlConnection($pdo);
        $calls = 0;

        $result = $connection->transaction(function () use (&$calls): int {
            return ++$calls;
        }, attempts: 2);

        expect($result)->toBe(2)
            ->and($pdo->statements)->toBe(['BEGIN', 'COMMIT', 'ROLLBACK', 'BEGIN', 'COMMIT']);
    });

    it('does not retry when an after-commit callback throws a conflict', function (): void {
        $connection = makeRetryMySqlConnection(new ScriptedPdo());
        $calls = 0;

        $run = function () use ($connection, &$calls): void {
            $connection->transaction(function () use ($connection, &$calls): void {
                $calls++;
                $connection->afterCommit(function (): void {
                    throw mysqlDeadlock();
                });
            }, attempts: 3);
        };

        expect($run)->toThrow(DeadlockException::class)
            ->and($calls)->toBe(1);
    });

    it('rejects an attempts value below one, even in a nested call, before opening a level', function (): void {
        $pdo = new ScriptedPdo();
        $connection = makeRetryMySqlConnection($pdo);
        $levelAfterNested = null;

        expect(fn () => $connection->transaction(fn () => null, attempts: 0))
            ->toThrow(TransactionException::class, 'at least 1 attempt')
            ->and($pdo->statements)->toBeEmpty();

        $connection->transaction(function () use ($connection, &$levelAfterNested): void {
            try {
                $connection->transaction(fn () => null, attempts: -1);
            } catch (TransactionException) {
                $levelAfterNested = $connection->transactionLevel();
            }
        });

        expect($levelAfterNested)->toBe(1)
            ->and($pdo->statements)->toBe(['BEGIN', 'COMMIT']);
    });

    it('translates a failed COMMIT into a typed exception carrying the COMMIT statement', function (): void {
        $pdo = new ScriptedPdo();
        $pdo->commitFailures[] = mysqlCommitFailure('40001', 1213, 'Deadlock found when trying to get lock');
        $pdo->commitFailures[] = mysqlCommitFailure('HY000', 2006, 'MySQL server has gone away');
        $connection = makeRetryMySqlConnection($pdo);

        $connection->beginTransaction();
        $conflict = null;

        try {
            $connection->commit();
        } catch (DeadlockException $e) {
            $conflict = $e;
        }

        $connection->beginTransaction();

        expect($conflict?->sql())->toBe('COMMIT')
            ->and($conflict)->toBeInstanceOf(TransactionConflictException::class)
            ->and($conflict?->getPrevious())->toBeInstanceOf(PDOException::class)
            ->and(fn () => $connection->commit())->toThrow(QueryException::class, 'server has gone away')
            ->and($connection->transactionLevel())->toBe(0);
    });
});
