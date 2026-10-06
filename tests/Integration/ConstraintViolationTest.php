<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Integration;

use Marko\Database\Exceptions\CheckConstraintViolationException;
use Marko\Database\Exceptions\ForeignKeyConstraintViolationException;
use Marko\Database\Exceptions\NotNullConstraintViolationException;
use Marko\Database\Exceptions\UniqueConstraintViolationException;
use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\MySql\Tests\Fixtures\Constraint\ConstraintPost;
use Marko\Database\MySql\Tests\Fixtures\Constraint\ConstraintPostRepository;
use Marko\Database\MySql\Tests\Fixtures\Constraint\ConstraintUser;
use Marko\Database\MySql\Tests\Fixtures\Constraint\ConstraintUserRepository;
use Marko\Database\MySql\Tests\Fixtures\IntegrationDatabase;
use Marko\Database\MySql\Tests\Fixtures\SharedConnection\SharedConnectionContainer;
use PDOException;
use Throwable;

/*
 * Runs against a real MySQL server. Set MARKO_TEST_MYSQL_HOST (and
 * optionally MARKO_TEST_MYSQL_PORT, _DATABASE, _USERNAME, _PASSWORD) to
 * enable; the tests skip otherwise. The tests create and drop the
 * constraint_users and constraint_posts tables.
 *
 * Settings come from tests/Fixtures/IntegrationDatabase. With
 * MARKO_INTEGRATION_REQUIRED set (CI), a missing host fails instead of
 * skipping. Part of the integration-services group.
 */

/**
 * Run the callback and return what it threw, or null.
 */
function mysqlConstraintCatch(
    callable $callback,
): ?Throwable {
    try {
        $callback();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

pest()->group('integration-services');

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->schema = new MySqlConnection($config);
    $this->schema->execute('DROP TABLE IF EXISTS constraint_posts, constraint_users');
    $this->schema->execute(
        'CREATE TABLE constraint_users (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NOT NULL, '
        . 'CONSTRAINT constraint_users_email_unique UNIQUE (email)) ENGINE=InnoDB',
    );
    $this->schema->execute(
        'CREATE TABLE constraint_posts (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, '
        . 'CONSTRAINT constraint_posts_user_id_foreign FOREIGN KEY (user_id) REFERENCES constraint_users (id), '
        . 'CONSTRAINT constraint_posts_user_id_positive CHECK (user_id > 0)) '
        . 'ENGINE=InnoDB',
    );

    $this->container = SharedConnectionContainer::build($config);
    $this->users = $this->container->get(ConstraintUserRepository::class);
    $this->posts = $this->container->get(ConstraintPostRepository::class);
});

afterEach(function (): void {
    if (isset($this->schema)) {
        $this->schema->execute('DROP TABLE IF EXISTS constraint_posts, constraint_users');
        $this->schema->disconnect();
    }
});

function mysqlConstraintUser(
    string $email,
): ConstraintUser {
    $user = new ConstraintUser();
    $user->email = $email;

    return $user;
}

describe('MySQL constraint violations', function (): void {
    it('throws a unique violation naming the constraint when saving a duplicate', function (): void {
        $this->users->save(mysqlConstraintUser('taken@example.com'));

        $exception = mysqlConstraintCatch(fn () => $this->users->save(mysqlConstraintUser('taken@example.com')));

        expect($exception)->toBeInstanceOf(UniqueConstraintViolationException::class)
            ->and($exception->constraintName())->toBe('constraint_users_email_unique')
            ->and($exception->table())->toBe('constraint_users')
            ->and($exception->sqlState())->toBe('23000');
    });

    it('throws a foreign key violation when deleting a referenced row', function (): void {
        $user = mysqlConstraintUser('author@example.com');
        $this->users->save($user);
        $post = new ConstraintPost();
        $post->userId = (int) $user->id;
        $this->posts->save($post);

        $exception = mysqlConstraintCatch(fn () => $this->users->delete($user));

        expect($exception)->toBeInstanceOf(ForeignKeyConstraintViolationException::class)
            ->and($exception->constraintName())->toBe('constraint_posts_user_id_foreign')
            ->and($exception->table())->toBe('constraint_posts');
    });

    it('throws a foreign key violation when inserting a row with a missing parent', function (): void {
        $post = new ConstraintPost();
        $post->userId = 999999;

        $exception = mysqlConstraintCatch(fn () => $this->posts->save($post));

        expect($exception)->toBeInstanceOf(ForeignKeyConstraintViolationException::class)
            ->and($exception->constraintName())->toBe('constraint_posts_user_id_foreign')
            ->and($exception->table())->toBe('constraint_posts');
    });

    it('throws a not-null violation naming the column for a raw insert', function (): void {
        $exception = mysqlConstraintCatch(
            fn () => $this->schema->execute('INSERT INTO constraint_users (email) VALUES (?)', [null]),
        );

        expect($exception)->toBeInstanceOf(NotNullConstraintViolationException::class)
            ->and($exception->column())->toBe('email')
            ->and($exception->table())->toBe('constraint_users');
    });

    it('throws a check violation naming the constraint', function (): void {
        $post = new ConstraintPost();
        $post->userId = -1;

        $exception = mysqlConstraintCatch(fn () => $this->posts->save($post));

        expect($exception)->toBeInstanceOf(CheckConstraintViolationException::class)
            ->and($exception->constraintName())->toBe('constraint_posts_user_id_positive')
            ->and($exception->table())->toBe('constraint_posts');
    });

    it('keeps the original PDOException as the previous exception', function (): void {
        $this->users->save(mysqlConstraintUser('taken@example.com'));

        $exception = mysqlConstraintCatch(fn () => $this->users->save(mysqlConstraintUser('taken@example.com')));

        expect($exception?->getPrevious())->toBeInstanceOf(PDOException::class)
            ->and($exception?->getPrevious()?->getCode())->toBe('23000');
    });

    it('does not leak the duplicate value into the message', function (): void {
        $this->users->save(mysqlConstraintUser('private@example.com'));

        $exception = mysqlConstraintCatch(fn () => $this->users->save(mysqlConstraintUser('private@example.com')));

        expect($exception)->toBeInstanceOf(UniqueConstraintViolationException::class)
            ->and($exception->getMessage())->not->toContain('private@example.com')
            ->and($exception->getContext())->not->toContain('private@example.com')
            ->and($exception->getPrevious()?->getMessage())->toContain('private@example.com');
    });

    it('rethrows the typed violation from insertBatch with the PDOException as previous', function (): void {
        $this->users->save(mysqlConstraintUser('taken@example.com'));

        $exception = mysqlConstraintCatch(fn () => $this->users->insertBatch([
            mysqlConstraintUser('fresh@example.com'),
            mysqlConstraintUser('taken@example.com'),
        ]));

        expect($exception)->toBeInstanceOf(UniqueConstraintViolationException::class)
            ->and($exception->constraintName())->toBe('constraint_users_email_unique')
            ->and($exception->getPrevious())->toBeInstanceOf(PDOException::class)
            ->and((int) $this->schema->query('SELECT COUNT(*) AS total FROM constraint_users')[0]['total'])->toBe(1);
    });
});
