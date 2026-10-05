<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Query;

use Marko\Database\Exceptions\LockException;
use Marko\Database\Exceptions\UpsertException;
use Marko\Database\MySql\Query\MySqlQueryBuilder;

describe('MySqlQueryBuilder row locks', function (): void {
    it('appends FOR UPDATE for lockForUpdate', function (): void {
        $connection = new RecordingTransactionalConnection();

        (new MySqlQueryBuilder($connection))->table('jobs')->where('id', '=', 7)->lockForUpdate()->get();

        expect($connection->lastQuerySql)->toBe('SELECT * FROM `jobs` WHERE `id` = ? FOR UPDATE')
            ->and($connection->lastQueryBindings)->toBe([7]);
    });

    it('appends LOCK IN SHARE MODE for sharedLock', function (): void {
        $connection = new RecordingTransactionalConnection();

        (new MySqlQueryBuilder($connection))->table('jobs')->sharedLock()->get();

        expect($connection->lastQuerySql)->toBe('SELECT * FROM `jobs` LOCK IN SHARE MODE');
    });

    it('appends the lock clause after LIMIT and OFFSET', function (): void {
        $connection = new RecordingTransactionalConnection();

        (new MySqlQueryBuilder($connection))->table('jobs')->orderBy('id')->lockForUpdate()->limit(5)->offset(
            10,
        )->get();

        expect($connection->lastQuerySql)->toBe('SELECT * FROM `jobs` ORDER BY `id` ASC LIMIT 5 OFFSET 10 FOR UPDATE');
    });

    it('locks the single row fetched by first', function (): void {
        $connection = new RecordingTransactionalConnection();

        (new MySqlQueryBuilder($connection))->table('jobs')->lockForUpdate()->first();

        expect($connection->lastQuerySql)->toBe('SELECT * FROM `jobs` LIMIT 1 FOR UPDATE');
    });

    it('switches a shared lock to FOR SHARE when a modifier is set', function (): void {
        $connection = new RecordingTransactionalConnection();

        (new MySqlQueryBuilder($connection))->table('jobs')->sharedLock()->skipLocked()->get();

        expect($connection->lastQuerySql)->toBe('SELECT * FROM `jobs` FOR SHARE SKIP LOCKED');
    });

    it('appends SKIP LOCKED and NOWAIT modifiers', function (): void {
        $skip = new RecordingTransactionalConnection();
        $noWait = new RecordingTransactionalConnection();

        (new MySqlQueryBuilder($skip))->table('jobs')->skipLocked()->lockForUpdate()->get();
        (new MySqlQueryBuilder($noWait))->table('jobs')->sharedLock()->noWait()->get();

        expect($skip->lastQuerySql)->toBe('SELECT * FROM `jobs` FOR UPDATE SKIP LOCKED')
            ->and($noWait->lastQuerySql)->toBe('SELECT * FROM `jobs` FOR SHARE NOWAIT');
    });

    it('throws when a lock is used outside a transaction', function (): void {
        $connection = new RecordingTransactionalConnection(open: false);

        (new MySqlQueryBuilder($connection))->table('jobs')->lockForUpdate()->get();
    })->throws(LockException::class, "Cannot lock rows of 'jobs' outside a transaction");

    it('throws when a lock is used on a connection without transaction support', function (): void {
        (new MySqlQueryBuilder(new NonTransactionalConnection()))->table('jobs')->lockForUpdate()->get();
    })->throws(LockException::class, "Cannot lock rows of 'jobs' outside a transaction");

    it('throws when a lock modifier is used without a lock', function (): void {
        (new MySqlQueryBuilder(new RecordingTransactionalConnection()))->table('jobs')->skipLocked()->get();
    })->throws(LockException::class, 'skipLocked() requires lockForUpdate() or sharedLock()');

    it('throws when skipLocked and noWait are combined', function (): void {
        (new MySqlQueryBuilder(new RecordingTransactionalConnection()))
            ->table('jobs')
            ->lockForUpdate()
            ->skipLocked()
            ->noWait()
            ->get();
    })->throws(LockException::class, 'skipLocked() and noWait() cannot be combined');

    it('throws when a lock is combined with an aggregate', function (string $aggregate): void {
        $builder = (new MySqlQueryBuilder(new RecordingTransactionalConnection()))->table('jobs')->lockForUpdate();

        expect(fn () => $aggregate === 'count' ? $builder->count() : $builder->{$aggregate}('attempts'))
            ->toThrow(LockException::class, "Row locks cannot be used with $aggregate()");
    })->with(['count', 'min', 'max', 'sum', 'avg']);

    it('throws when a lock is combined with a union', function (): void {
        $connection = new RecordingTransactionalConnection();
        $other = (new MySqlQueryBuilder($connection))->table('archived_jobs');

        (new MySqlQueryBuilder($connection))->table('jobs')->lockForUpdate()->union($other)->get();
    })->throws(LockException::class, 'Row locks cannot be used with union()');

    it('throws when a locked query is used as a union subquery', function (): void {
        $connection = new RecordingTransactionalConnection();
        $locked = (new MySqlQueryBuilder($connection))->table('archived_jobs')->lockForUpdate();

        (new MySqlQueryBuilder($connection))->table('jobs')->union($locked)->get();
    })->throws(LockException::class, 'Row locks cannot be used with union()');
});

describe('MySqlQueryBuilder upsert', function (): void {
    it('compiles upsert with ON DUPLICATE KEY UPDATE using every non-unique column by default', function (): void {
        $connection = new RecordingTransactionalConnection(executeReturn: 2);

        $affected = (new MySqlQueryBuilder($connection))->table('users')->upsert(
            [
                ['email' => 'ada@example.com', 'name' => 'Ada', 'visits' => 1],
                ['email' => 'alan@example.com', 'name' => 'Alan', 'visits' => 3],
            ],
            ['email'],
        );

        expect($connection->lastExecuteSql)->toBe(
            'INSERT INTO `users` (`email`, `name`, `visits`) VALUES (?, ?, ?), (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `visits` = VALUES(`visits`)',
        )
            ->and($connection->lastExecuteBindings)->toBe(['ada@example.com', 'Ada', 1, 'alan@example.com', 'Alan', 3])
            ->and($affected)->toBe(2);
    });

    it('compiles upsert with an explicit update column list and composite conflict columns', function (): void {
        $connection = new RecordingTransactionalConnection();

        (new MySqlQueryBuilder($connection))->table('stock')->upsert(
            [['sku' => 'A1', 'warehouse' => 'north', 'quantity' => 5, 'note' => 'x']],
            ['sku', 'warehouse'],
            ['quantity'],
        );

        expect($connection->lastExecuteSql)->toBe(
            'INSERT INTO `stock` (`sku`, `warehouse`, `quantity`, `note`) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE `quantity` = VALUES(`quantity`)',
        );
    });

    it('compiles a no-op update when the update list is empty', function (): void {
        $connection = new RecordingTransactionalConnection();

        (new MySqlQueryBuilder($connection))->table('users')->upsert(
            [['email' => 'ada@example.com', 'name' => 'Ada']],
            ['email'],
            [],
        );

        expect($connection->lastExecuteSql)->toBe(
            'INSERT INTO `users` (`email`, `name`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `email` = `email`',
        );
    });

    it('compiles a no-op update when every column is a conflict column', function (): void {
        $connection = new RecordingTransactionalConnection();

        (new MySqlQueryBuilder($connection))->table('tags')->upsert([['slug' => 'php']], ['slug']);

        expect($connection->lastExecuteSql)->toBe(
            'INSERT INTO `tags` (`slug`) VALUES (?) ON DUPLICATE KEY UPDATE `slug` = `slug`',
        );
    });

    it('rejects empty rows', function (): void {
        (new MySqlQueryBuilder(new RecordingTransactionalConnection()))->table('users')->upsert([], ['email']);
    })->throws(UpsertException::class, 'Cannot upsert an empty set of rows');

    it('rejects an empty uniqueBy list', function (): void {
        (new MySqlQueryBuilder(new RecordingTransactionalConnection()))
            ->table('users')
            ->upsert([['email' => 'ada@example.com']], []);
    })->throws(UpsertException::class, 'requires at least one conflict column');

    it('rejects mismatched row columns', function (): void {
        (new MySqlQueryBuilder(new RecordingTransactionalConnection()))
            ->table('users')
            ->upsert([['email' => 'ada@example.com', 'name' => 'Ada'], ['email' => 'alan@example.com']], ['email']);
    })->throws(UpsertException::class, 'Row 1 has a different set of columns than row 0');
});
