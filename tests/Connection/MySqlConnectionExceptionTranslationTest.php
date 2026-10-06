<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Connection;

use Marko\Database\Exceptions\QueryException;
use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\MySql\Connection\MySqlExceptionTranslator;
use Marko\Database\MySql\Exceptions\ConnectionException;
use Marko\Database\MySql\Tests\Fixtures\SharedConnection\SharedConnectionContainer;
use PDO;
use PDOException;

/**
 * Records what the connection hands the translator, so the tests can check
 * the SQL and bindings reach it unchanged.
 */
class RecordingMySqlExceptionTranslator extends MySqlExceptionTranslator
{
    public ?PDOException $exception = null;

    public ?string $sql = null;

    /** @var array<int|string, mixed>|null */
    public ?array $bindings = null;

    public function translate(
        PDOException $exception,
        string $sql,
        array $bindings,
    ): QueryException {
        $this->exception = $exception;
        $this->sql = $sql;
        $this->bindings = $bindings;

        return parent::translate($exception, $sql, $bindings);
    }
}

/**
 * A MySqlConnection backed by in-memory SQLite with a `users` table whose
 * email column is UNIQUE, so a duplicate insert raises a real PDOException.
 */
function sqliteBackedMySqlConnection(
    MySqlExceptionTranslator $exceptionTranslator,
): MySqlConnection {
    return new class (SharedConnectionContainer::config(), exceptionTranslator: $exceptionTranslator) extends MySqlConnection
    {
        protected function createPdo(
            string $dsn,
            string $username,
            string $password,
            array $options,
        ): PDO {
            $pdo = new class ('sqlite::memory:', options: array_diff_key(
                $options,
                [PDO\Mysql::ATTR_INIT_COMMAND => true],
            )) extends PDO
            {
                public function exec(
                    string $statement,
                ): int|false {
                    return str_starts_with($statement, 'SET NAMES') ? 0 : parent::exec($statement);
                }
            };
            $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE)');
            $pdo->exec("INSERT INTO users (email) VALUES ('taken@example.com')");

            return $pdo;
        }
    };
}

describe('MySqlConnection exception translation', function (): void {
    it('throws a translated exception when query() fails', function (): void {
        $translator = new RecordingMySqlExceptionTranslator();
        $connection = sqliteBackedMySqlConnection($translator);

        expect(fn () => $connection->query('SELECT * FROM missing WHERE id = ?', [1]))
            ->toThrow(QueryException::class)
            ->and($translator->exception)->toBeInstanceOf(PDOException::class)
            ->and($translator->sql)->toBe('SELECT * FROM missing WHERE id = ?')
            ->and($translator->bindings)->toBe([1]);
    });

    it('throws a translated exception when execute() fails', function (): void {
        $translator = new RecordingMySqlExceptionTranslator();
        $connection = sqliteBackedMySqlConnection($translator);

        try {
            $connection->execute('INSERT INTO users (email) VALUES (?)', ['taken@example.com']);
            $caught = null;
        } catch (QueryException $e) {
            $caught = $e;
        }

        expect($caught)->toBeInstanceOf(QueryException::class)
            ->and($caught?->getPrevious())->toBe($translator->exception)
            ->and($caught?->sql())->toBe('INSERT INTO users (email) VALUES (?)')
            ->and($caught?->bindings())->toBe(['taken@example.com'])
            ->and($caught?->getMessage())->not->toContain('taken@example.com');
    });

    it('throws a translated exception when a prepared statement fails', function (): void {
        $translator = new RecordingMySqlExceptionTranslator();
        $connection = sqliteBackedMySqlConnection($translator);
        $statement = $connection->prepare('INSERT INTO users (email) VALUES (?)');

        expect(fn () => $statement->execute(['taken@example.com']))
            ->toThrow(QueryException::class)
            ->and($translator->sql)->toBe('INSERT INTO users (email) VALUES (?)')
            ->and($translator->bindings)->toBe(['taken@example.com']);
    });

    it('lets connection and array-binding exceptions pass through untranslated', function (): void {
        $translator = new RecordingMySqlExceptionTranslator();
        $connection = sqliteBackedMySqlConnection($translator);
        $unreachable = new MySqlConnection(
            SharedConnectionContainer::config(host: 'nonexistent.invalid.host'),
            exceptionTranslator: $translator,
        );

        expect(fn () => $connection->execute('INSERT INTO users (email) VALUES (?)', [["\xB1\x31"]]))
            ->toThrow(ConnectionException::class)
            ->and(fn () => $unreachable->query('SELECT 1'))->toThrow(ConnectionException::class)
            ->and($translator->exception)->toBeNull();
    });

    it('translates with the default translator when none is given', function (): void {
        $connection = new class (SharedConnectionContainer::config()) extends MySqlConnection
        {
            protected function createPdo(
                string $dsn,
                string $username,
                string $password,
                array $options,
            ): PDO {
                return new class ('sqlite::memory:', options: array_diff_key(
                    $options,
                    [PDO\Mysql::ATTR_INIT_COMMAND => true],
                )) extends PDO
                {
                    public function exec(
                        string $statement,
                    ): int|false {
                        return str_starts_with($statement, 'SET NAMES') ? 0 : parent::exec($statement);
                    }
                };
            }
        };

        expect(fn () => $connection->query('SELECT * FROM missing'))->toThrow(QueryException::class);
    });
});
