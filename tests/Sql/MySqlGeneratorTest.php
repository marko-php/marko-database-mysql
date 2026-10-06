<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Sql;

use Marko\Database\Diff\SchemaDiff;
use Marko\Database\Diff\SqlGeneratorInterface;
use Marko\Database\Diff\TableDiff;
use Marko\Database\Exceptions\MigrationException;
use Marko\Database\MySql\Sql\MySqlGenerator;
use Marko\Database\Schema\Column;
use Marko\Database\Schema\ForeignKey;
use Marko\Database\Schema\Index;
use Marko\Database\Schema\IndexType;
use Marko\Database\Schema\Table;

describe('MySqlGenerator', function (): void {
    it('implements SqlGeneratorInterface', function (): void {
        $generator = new MySqlGenerator();

        expect($generator)->toBeInstanceOf(SqlGeneratorInterface::class);
    });

    it('generates CREATE TABLE with all column definitions', function (): void {
        $generator = new MySqlGenerator();

        $table = new Table(
            name: 'users',
            columns: [
                new Column(name: 'id', type: 'integer', primaryKey: true, autoIncrement: true),
                new Column(name: 'email', type: 'string', length: 255, unique: true),
                new Column(name: 'name', type: 'string', length: 100, nullable: true),
                new Column(name: 'created_at', type: 'datetime', default: 'CURRENT_TIMESTAMP'),
            ],
        );

        $sql = $generator->generateCreateTable($table);

        expect($sql)
            ->toContain('CREATE TABLE `users`')
            ->and($sql)->toContain('`id` INT NOT NULL AUTO_INCREMENT')
            ->and($sql)->toContain('`email` VARCHAR(255) NOT NULL UNIQUE')
            ->and($sql)->toContain('`name` VARCHAR(100) NULL')
            ->and($sql)->toContain('`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP')
            ->and($sql)->toContain('PRIMARY KEY (`id`)');
    });

    it('generates DROP TABLE statements', function (): void {
        $generator = new MySqlGenerator();

        $sql = $generator->generateDropTable('users');

        expect($sql)->toBe('DROP TABLE `users`');
    });

    it('generates ALTER TABLE ADD COLUMN', function (): void {
        $generator = new MySqlGenerator();

        $column = new Column(
            name: 'bio',
            type: 'text',
            nullable: true,
        );

        $sql = $generator->generateAddColumn('users', $column);

        expect($sql)->toBe('ALTER TABLE `users` ADD COLUMN `bio` TEXT NULL');
    });

    it('generates ALTER TABLE DROP COLUMN', function (): void {
        $generator = new MySqlGenerator();

        $sql = $generator->generateDropColumn('users', 'bio');

        expect($sql)->toBe('ALTER TABLE `users` DROP COLUMN `bio`');
    });

    it('generates ALTER TABLE MODIFY COLUMN for type changes', function (): void {
        $generator = new MySqlGenerator();

        $oldColumn = new Column(name: 'name', type: 'string', length: 100, nullable: true);
        $newColumn = new Column(name: 'name', type: 'string', length: 255, nullable: false);

        $sql = $generator->generateModifyColumn('users', $newColumn, $oldColumn);

        expect($sql)->toBe('ALTER TABLE `users` MODIFY COLUMN `name` VARCHAR(255) NOT NULL');
    });

    it('throws when adding a partial index on mysql', function (): void {
        $index = new Index(name: 'shows_live_idx', columns: ['status'], where: "status = 'live'");

        expect(fn () => new MySqlGenerator()->generateAddIndex('shows', $index))
            ->toThrow(MigrationException::class);
    });

    it('throws when creating a table with a partial index on mysql', function (): void {
        $table = new Table(
            name: 'shows',
            columns: [new Column(name: 'id', type: 'integer', primaryKey: true, autoIncrement: true)],
            indexes: [new Index(name: 'shows_live_idx', columns: ['id'], where: 'id > 0')],
        );

        expect(fn () => new MySqlGenerator()->generateCreateTable($table))
            ->toThrow(MigrationException::class);
    });

    it('names the index and suggests an alternative in the exception', function (): void {
        $index = new Index(name: 'shows_live_idx', columns: ['status'], where: "status = 'live'");

        try {
            new MySqlGenerator()->generateAddIndex('shows', $index);
            $this->fail('Expected MigrationException');
        } catch (MigrationException $e) {
            expect($e->getMessage())->toContain('shows_live_idx')
                ->and($e->getMessage())->toContain('MySQL')
                ->and($e->getSuggestion())->toContain('unmanagedIndexes');
        }
    });

    it('generates CREATE INDEX statements', function (): void {
        $generator = new MySqlGenerator();

        $index = new Index(
            name: 'idx_users_email',
            columns: ['email'],
            type: IndexType::Btree,
        );

        $sql = $generator->generateAddIndex('users', $index);

        expect($sql)->toBe('CREATE INDEX `idx_users_email` ON `users` (`email`)');
    });

    it('generates CREATE UNIQUE INDEX statements', function (): void {
        $generator = new MySqlGenerator();

        $index = new Index(
            name: 'idx_users_email_unique',
            columns: ['email'],
            type: IndexType::Unique,
        );

        $sql = $generator->generateAddIndex('users', $index);

        expect($sql)->toBe('CREATE UNIQUE INDEX `idx_users_email_unique` ON `users` (`email`)');
    });

    it('generates CREATE FULLTEXT INDEX statements', function (): void {
        $generator = new MySqlGenerator();

        $index = new Index(
            name: 'idx_posts_content_fulltext',
            columns: ['title', 'content'],
            type: IndexType::Fulltext,
        );

        $sql = $generator->generateAddIndex('posts', $index);

        expect($sql)->toBe('CREATE FULLTEXT INDEX `idx_posts_content_fulltext` ON `posts` (`title`, `content`)');
    });

    it('generates DROP INDEX statements', function (): void {
        $generator = new MySqlGenerator();

        $sql = $generator->generateDropIndex('users', 'idx_users_email');

        expect($sql)->toBe('DROP INDEX `idx_users_email` ON `users`');
    });

    it('generates ALTER TABLE ADD CONSTRAINT for foreign keys', function (): void {
        $generator = new MySqlGenerator();

        $foreignKey = new ForeignKey(
            name: 'fk_posts_user_id',
            columns: ['user_id'],
            referencedTable: 'users',
            referencedColumns: ['id'],
            onDelete: 'CASCADE',
            onUpdate: 'CASCADE',
        );

        $sql = $generator->generateAddForeignKey('posts', $foreignKey);

        expect($sql)->toBe(
            'ALTER TABLE `posts` ADD CONSTRAINT `fk_posts_user_id` ' .
            'FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE',
        );
    });

    it('generates ALTER TABLE ADD CONSTRAINT without ON DELETE/UPDATE when null', function (): void {
        $generator = new MySqlGenerator();

        $foreignKey = new ForeignKey(
            name: 'fk_posts_user_id',
            columns: ['user_id'],
            referencedTable: 'users',
            referencedColumns: ['id'],
        );

        $sql = $generator->generateAddForeignKey('posts', $foreignKey);

        expect($sql)->toBe(
            'ALTER TABLE `posts` ADD CONSTRAINT `fk_posts_user_id` ' .
            'FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)',
        );
    });

    it('generates ALTER TABLE DROP FOREIGN KEY', function (): void {
        $generator = new MySqlGenerator();

        $sql = $generator->generateDropForeignKey('posts', 'fk_posts_user_id');

        expect($sql)->toBe('ALTER TABLE `posts` DROP FOREIGN KEY `fk_posts_user_id`');
    });

    it('maps Column types to MySQL data types', function (): void {
        $generator = new MySqlGenerator();

        // Test integer types
        $intTable = new Table(name: 't', columns: [new Column(name: 'c', type: 'integer')]);
        expect($generator->generateCreateTable($intTable))->toContain('`c` INT NOT NULL');

        $bigintTable = new Table(name: 't', columns: [new Column(name: 'c', type: 'bigint')]);
        expect($generator->generateCreateTable($bigintTable))->toContain('`c` BIGINT NOT NULL');

        $smallintTable = new Table(name: 't', columns: [new Column(name: 'c', type: 'smallint')]);
        expect($generator->generateCreateTable($smallintTable))->toContain('`c` SMALLINT NOT NULL');

        $tinyintTable = new Table(name: 't', columns: [new Column(name: 'c', type: 'tinyint')]);
        expect($generator->generateCreateTable($tinyintTable))->toContain('`c` TINYINT NOT NULL');

        // Test string types
        $stringTable = new Table(name: 't', columns: [new Column(name: 'c', type: 'string', length: 100)]);
        expect($generator->generateCreateTable($stringTable))->toContain('`c` VARCHAR(100) NOT NULL');

        $textTable = new Table(name: 't', columns: [new Column(name: 'c', type: 'text')]);
        expect($generator->generateCreateTable($textTable))->toContain('`c` TEXT NOT NULL');

        // Test boolean
        $boolTable = new Table(name: 't', columns: [new Column(name: 'c', type: 'boolean')]);
        expect($generator->generateCreateTable($boolTable))->toContain('`c` TINYINT(1) NOT NULL');

        // Test date/time types
        $datetimeTable = new Table(name: 't', columns: [new Column(name: 'c', type: 'datetime')]);
        expect($generator->generateCreateTable($datetimeTable))->toContain('`c` DATETIME NOT NULL');

        $dateTable = new Table(name: 't', columns: [new Column(name: 'c', type: 'date')]);
        expect($generator->generateCreateTable($dateTable))->toContain('`c` DATE NOT NULL');

        $timeTable = new Table(name: 't', columns: [new Column(name: 'c', type: 'time')]);
        expect($generator->generateCreateTable($timeTable))->toContain('`c` TIME NOT NULL');

        $timestampTable = new Table(name: 't', columns: [new Column(name: 'c', type: 'timestamp')]);
        expect($generator->generateCreateTable($timestampTable))->toContain('`c` TIMESTAMP NOT NULL');

        // Test decimal/float types
        $decimalTable = new Table(name: 't', columns: [new Column(name: 'c', type: 'decimal')]);
        expect($generator->generateCreateTable($decimalTable))->toContain('`c` DECIMAL(10,2) NOT NULL');

        $floatTable = new Table(name: 't', columns: [new Column(name: 'c', type: 'float')]);
        expect($generator->generateCreateTable($floatTable))->toContain('`c` FLOAT NOT NULL');

        // Test binary types
        $blobTable = new Table(name: 't', columns: [new Column(name: 'c', type: 'blob')]);
        expect($generator->generateCreateTable($blobTable))->toContain('`c` BLOB NOT NULL');

        // Test JSON
        $jsonTable = new Table(name: 't', columns: [new Column(name: 'c', type: 'json')]);
        expect($generator->generateCreateTable($jsonTable))->toContain('`c` JSON NOT NULL');
    });

    it('handles AUTO_INCREMENT for serial columns', function (): void {
        $generator = new MySqlGenerator();

        $table = new Table(
            name: 'posts',
            columns: [
                new Column(name: 'id', type: 'integer', primaryKey: true, autoIncrement: true),
                new Column(name: 'title', type: 'string', length: 255),
            ],
        );

        $sql = $generator->generateCreateTable($table);

        expect($sql)
            ->toContain('`id` INT NOT NULL AUTO_INCREMENT')
            ->and($sql)->toContain('PRIMARY KEY (`id`)');
    });

    it('generates proper DEFAULT expressions', function (): void {
        $generator = new MySqlGenerator();

        // String default
        $stringDefault = new Table(name: 't', columns: [
            new Column(name: 'status', type: 'string', length: 20, default: 'active'),
        ]);
        expect($generator->generateCreateTable($stringDefault))->toContain("DEFAULT 'active'");

        // Numeric default
        $numericDefault = new Table(name: 't', columns: [
            new Column(name: 'count', type: 'integer', default: 0),
        ]);
        expect($generator->generateCreateTable($numericDefault))->toContain('DEFAULT 0');

        // Boolean default
        $boolDefault = new Table(name: 't', columns: [
            new Column(name: 'is_active', type: 'boolean', default: true),
        ]);
        expect($generator->generateCreateTable($boolDefault))->toContain('DEFAULT 1');

        // NULL default (with nullable column)
        $nullDefault = new Table(name: 't', columns: [
            new Column(name: 'optional', type: 'string', length: 100, nullable: true, default: null),
        ]);
        $sql = $generator->generateCreateTable($nullDefault);
        expect($sql)->toContain('`optional` VARCHAR(100) NULL');

        // Expression default (CURRENT_TIMESTAMP)
        $expressionDefault = new Table(name: 't', columns: [
            new Column(name: 'created_at', type: 'datetime', default: 'CURRENT_TIMESTAMP'),
        ]);
        expect($generator->generateCreateTable($expressionDefault))->toContain('DEFAULT CURRENT_TIMESTAMP');
    });

    it('generates down SQL that reverses up SQL', function (): void {
        $generator = new MySqlGenerator();

        // Create a schema diff that creates a table
        $table = new Table(
            name: 'posts',
            columns: [
                new Column(name: 'id', type: 'integer', primaryKey: true, autoIncrement: true),
                new Column(name: 'title', type: 'string', length: 255),
            ],
            indexes: [
                new Index(name: 'idx_posts_title', columns: ['title']),
            ],
        );

        $diff = new SchemaDiff(
            tablesToCreate: [$table],
        );

        $upSql = $generator->generateUp($diff);
        $downSql = $generator->generateDown($diff);

        // Up should create table, down should drop table (reverse of create)
        expect($upSql[0])
            ->toContain('CREATE TABLE `posts`')
            ->and($downSql)->toContain('DROP TABLE `posts`');
    });

    it('generates up SQL for all schema changes', function (): void {
        $generator = new MySqlGenerator();

        $newTable = new Table(
            name: 'categories',
            columns: [
                new Column(name: 'id', type: 'integer', primaryKey: true, autoIncrement: true),
                new Column(name: 'name', type: 'string', length: 100),
            ],
        );

        $tableToDropColumns = new Table(name: 'old_table');

        $tableDiff = new TableDiff(
            tableName: 'posts',
            columnsToAdd: [new Column(name: 'category_id', type: 'integer')],
            columnsToDrop: [new Column(name: 'old_column', type: 'string')],
            indexesToAdd: [new Index(name: 'idx_posts_category', columns: ['category_id'])],
            foreignKeysToAdd: [new ForeignKey(
                name: 'fk_posts_category',
                columns: ['category_id'],
                referencedTable: 'categories',
                referencedColumns: ['id'],
            )],
        );

        $diff = new SchemaDiff(
            tablesToCreate: [$newTable],
            tablesToDrop: [$tableToDropColumns],
            tablesToAlter: ['posts' => $tableDiff],
        );

        $upSql = $generator->generateUp($diff);

        // Should contain: create table, drop table, add column, drop column, create index, add foreign key
        expect(array_filter($upSql, fn ($s) => str_contains($s, 'CREATE TABLE `categories`')))
            ->not->toBeEmpty()
            ->and($upSql)->toContain('DROP TABLE `old_table`')
            ->and(array_filter($upSql, fn ($s) => str_contains($s, 'ADD COLUMN `category_id`')))->not->toBeEmpty()
            ->and(array_filter($upSql, fn ($s) => str_contains($s, 'DROP COLUMN `old_column`')))->not->toBeEmpty()
            ->and(
                array_filter($upSql, fn ($s) => str_contains($s, 'CREATE INDEX `idx_posts_category`')),
            )->not->toBeEmpty()
            ->and(
                array_filter($upSql, fn ($s) => str_contains($s, 'ADD CONSTRAINT `fk_posts_category`')),
            )->not->toBeEmpty();
    });

    it('generates down SQL that reverses table alterations', function (): void {
        $generator = new MySqlGenerator();

        $columnAdded = new Column(name: 'new_column', type: 'string', length: 100);
        $columnDropped = new Column(name: 'dropped_column', type: 'text');
        $indexAdded = new Index(name: 'idx_new', columns: ['new_column']);
        $indexDropped = new Index(name: 'idx_dropped', columns: ['dropped_column']);
        $fkAdded = new ForeignKey(
            name: 'fk_new',
            columns: ['ref_id'],
            referencedTable: 'refs',
            referencedColumns: ['id'],
        );
        $fkDropped = new ForeignKey(
            name: 'fk_dropped',
            columns: ['old_ref_id'],
            referencedTable: 'old_refs',
            referencedColumns: ['id'],
        );

        $tableDiff = new TableDiff(
            tableName: 'posts',
            columnsToAdd: [$columnAdded],
            columnsToDrop: [$columnDropped],
            indexesToAdd: [$indexAdded],
            indexesToDrop: [$indexDropped],
            foreignKeysToAdd: [$fkAdded],
            foreignKeysToDrop: [$fkDropped],
        );

        $diff = new SchemaDiff(
            tablesToAlter: ['posts' => $tableDiff],
        );

        $downSql = $generator->generateDown($diff);

        // Verify all reversals: add→drop, drop→add for columns, indexes, and foreign keys
        expect(array_filter($downSql, fn ($s) => str_contains($s, 'DROP COLUMN `new_column`')))
            ->not->toBeEmpty()
            ->and(array_filter($downSql, fn ($s) => str_contains($s, 'ADD COLUMN `dropped_column`')))->not->toBeEmpty()
            ->and(array_filter($downSql, fn ($s) => str_contains($s, 'DROP INDEX `idx_new`')))->not->toBeEmpty()
            ->and(array_filter($downSql, fn ($s) => str_contains($s, 'CREATE INDEX `idx_dropped`')))->not->toBeEmpty()
            ->and(array_filter($downSql, fn ($s) => str_contains($s, 'DROP FOREIGN KEY `fk_new`')))->not->toBeEmpty()
            ->and(
                array_filter($downSql, fn ($s) => str_contains($s, 'ADD CONSTRAINT `fk_dropped`')),
            )->not->toBeEmpty();
    });

    it('generates multi-column index SQL', function (): void {
        $generator = new MySqlGenerator();

        $index = new Index(
            name: 'idx_posts_user_created',
            columns: ['user_id', 'created_at'],
        );

        $sql = $generator->generateAddIndex('posts', $index);

        expect($sql)->toBe('CREATE INDEX `idx_posts_user_created` ON `posts` (`user_id`, `created_at`)');
    });

    it('generates multi-column foreign key SQL', function (): void {
        $generator = new MySqlGenerator();

        $foreignKey = new ForeignKey(
            name: 'fk_order_items_composite',
            columns: ['order_id', 'product_id'],
            referencedTable: 'order_products',
            referencedColumns: ['order_id', 'product_id'],
            onDelete: 'CASCADE',
        );

        $sql = $generator->generateAddForeignKey('order_items', $foreignKey);

        expect($sql)->toBe(
            'ALTER TABLE `order_items` ADD CONSTRAINT `fk_order_items_composite` ' .
            'FOREIGN KEY (`order_id`, `product_id`) REFERENCES `order_products` (`order_id`, `product_id`) ON DELETE CASCADE',
        );
    });

    it('generates CREATE TABLE with indexes', function (): void {
        $generator = new MySqlGenerator();

        $table = new Table(
            name: 'posts',
            columns: [
                new Column(name: 'id', type: 'integer', primaryKey: true, autoIncrement: true),
                new Column(name: 'user_id', type: 'integer'),
                new Column(name: 'title', type: 'string', length: 255),
            ],
            indexes: [
                new Index(name: 'idx_posts_user_id', columns: ['user_id']),
            ],
        );

        $sql = $generator->generateCreateTable($table);

        expect($sql)->toContain('INDEX `idx_posts_user_id` (`user_id`)');
    });

    it('generates CREATE TABLE with foreign keys', function (): void {
        $generator = new MySqlGenerator();

        $table = new Table(
            name: 'posts',
            columns: [
                new Column(name: 'id', type: 'integer', primaryKey: true, autoIncrement: true),
                new Column(name: 'user_id', type: 'integer'),
            ],
            foreignKeys: [
                new ForeignKey(
                    name: 'fk_posts_user_id',
                    columns: ['user_id'],
                    referencedTable: 'users',
                    referencedColumns: ['id'],
                    onDelete: 'CASCADE',
                ),
            ],
        );

        $sql = $generator->generateCreateTable($table);

        expect($sql)->toContain(
            'CONSTRAINT `fk_posts_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE',
        );
    });

    it('defaults VARCHAR to 255 when no length specified', function (): void {
        $generator = new MySqlGenerator();

        $table = new Table(
            name: 'posts',
            columns: [
                new Column(name: 'title', type: 'string'),  // No length specified
            ],
        );

        $sql = $generator->generateCreateTable($table);

        expect($sql)->toContain('`title` VARCHAR(255) NOT NULL');
    });

    it('forces NOT NULL for primary key columns even when marked nullable', function (): void {
        $generator = new MySqlGenerator();

        // Simulates entity with ?int $id = null (nullable in PHP but must be NOT NULL in DB)
        $table = new Table(
            name: 'posts',
            columns: [
                new Column(name: 'id', type: 'integer', primaryKey: true, autoIncrement: true, nullable: true),
                new Column(name: 'title', type: 'string', length: 255),
            ],
        );

        $sql = $generator->generateCreateTable($table);

        expect($sql)->toContain('`id` INT NOT NULL AUTO_INCREMENT');
    });

    it('forces NOT NULL for auto-increment columns even when marked nullable', function (): void {
        $generator = new MySqlGenerator();

        $table = new Table(
            name: 'posts',
            columns: [
                new Column(name: 'id', type: 'integer', autoIncrement: true, nullable: true),
            ],
        );

        $sql = $generator->generateCreateTable($table);

        expect($sql)->toContain('`id` INT NOT NULL AUTO_INCREMENT');
    });

    it('generates a valid MySQL type for a uuid column', function (): void {
        $generator = new MySqlGenerator();

        $table = new Table(
            name: 't',
            columns: [new Column(name: 'id', type: 'uuid')],
        );

        $sql = $generator->generateCreateTable($table);

        expect($sql)->toContain('`id` CHAR(36) NOT NULL');
    });

    it('generates a valid MySQL type for an enum column', function (): void {
        $generator = new MySqlGenerator();

        $table = new Table(
            name: 't',
            columns: [new Column(name: 'status', type: 'enum', length: 50)],
        );

        $sql = $generator->generateCreateTable($table);

        expect($sql)->toContain('`status` VARCHAR(50) NOT NULL');
    });

    it('generates DECIMAL with the shared precision for a decimal column', function (): void {
        $generator = new MySqlGenerator();

        $table = new Table(
            name: 't',
            columns: [new Column(name: 'price', type: 'decimal')],
        );

        $sql = $generator->generateCreateTable($table);

        expect($sql)->toContain('`price` DECIMAL(10,2) NOT NULL');
    });

    it('emits MySQL JSON DDL type for #[Column(type: \'json\')]', function (): void {
        $generator = new MySqlGenerator();

        $table = new Table(
            name: 'products',
            columns: [
                new Column(name: 'id', type: 'integer', primaryKey: true, autoIncrement: true),
                new Column(name: 'metadata', type: 'json'),
            ],
        );

        $sql = $generator->generateCreateTable($table);

        expect($sql)->toContain('`metadata` JSON NOT NULL');
    });

    it('generates an up MODIFY COLUMN from the new column definition', function (): void {
        $statements = new MySqlGenerator()->generateUp(mysqlModifyDiff(
            new Column(name: 'views', type: 'bigint', nullable: true, default: 0),
            new Column(name: 'views', type: 'integer'),
        ));

        expect($statements)->toBe(['ALTER TABLE `posts` MODIFY COLUMN `views` BIGINT NULL DEFAULT 0']);
    });

    it('restores the old type, nullability and default of a modified column in a down migration', function (): void {
        $statements = new MySqlGenerator()->generateDown(mysqlModifyDiff(
            new Column(name: 'status', type: 'text', nullable: true),
            new Column(name: 'status', type: 'string', length: 20, default: 'draft'),
        ));

        expect($statements)->toBe(["ALTER TABLE `posts` MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'draft'"]);
    });

    it('restores a CURRENT_TIMESTAMP default unquoted in a down migration', function (): void {
        $statements = new MySqlGenerator()->generateDown(mysqlModifyDiff(
            new Column(name: 'created_at', type: 'timestamp', nullable: true),
            new Column(name: 'created_at', type: 'timestamp', default: 'CURRENT_TIMESTAMP'),
        ));

        expect($statements)->toBe([
            'ALTER TABLE `posts` MODIFY COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ]);
    });

    it('restores modified columns before re-adding dropped foreign keys in a down migration', function (): void {
        $foreignKey = new ForeignKey(
            name: 'posts_author_id_foreign',
            columns: ['author_id'],
            referencedTable: 'users',
            referencedColumns: ['id'],
        );
        $diff = new SchemaDiff(tablesToAlter: [
            'posts' => new TableDiff(
                tableName: 'posts',
                columnsToModify: ['author_id' => new Column(name: 'author_id', type: 'bigint')],
                foreignKeysToDrop: [$foreignKey],
                columnsToModifyFrom: ['author_id' => new Column(name: 'author_id', type: 'integer')],
            ),
        ]);

        $statements = new MySqlGenerator()->generateDown($diff);

        expect($statements)->toHaveCount(2)
            ->and($statements[0])->toBe('ALTER TABLE `posts` MODIFY COLUMN `author_id` INT NOT NULL')
            ->and($statements[1])->toContain('ADD CONSTRAINT `posts_author_id_foreign`');
    });

    it('throws a MigrationException naming the column when the diff lacks the previous column', function (): void {
        $diff = new SchemaDiff(tablesToAlter: [
            'posts' => new TableDiff(
                tableName: 'posts',
                columnsToModify: ['status' => new Column(name: 'status', type: 'string', default: 'live')],
            ),
        ]);

        expect(fn () => new MySqlGenerator()->generateDown($diff))->toThrow(
            MigrationException::class,
            "Column 'posts.status' is modified, but the diff holds no previous definition for it",
        )->and(fn () => new MySqlGenerator()->generateUp($diff))->toThrow(
            MigrationException::class,
            "Column 'posts.status' is modified, but the diff holds no previous definition for it",
        );
    });

    describe('MODIFY COLUMN fidelity', function (): void {
        it('keeps the existing VARCHAR length when the entity declares none', function (): void {
            $statements = new MySqlGenerator()->generateUp(mysqlModifyDiff(
                new Column(name: 'title', type: 'varchar', nullable: true),
                new Column(name: 'title', type: 'VARCHAR', length: 500, nativeType: 'varchar(500)'),
            ));

            expect($statements)->toBe(['ALTER TABLE `posts` MODIFY COLUMN `title` varchar(500) NULL']);
        });

        it('keeps the existing default when the entity declares none', function (): void {
            $statements = new MySqlGenerator()->generateUp(mysqlModifyDiff(
                new Column(name: 'status', type: 'varchar', length: 20, nullable: true),
                new Column(name: 'status', type: 'VARCHAR', length: 20, default: 'draft'),
            ));

            expect($statements)->toBe(["ALTER TABLE `posts` MODIFY COLUMN `status` VARCHAR(20) NULL DEFAULT 'draft'"]);
        });

        it('emits no MODIFY COLUMN when the target matches the previous column', function (): void {
            $diff = mysqlModifyDiff(
                new Column(name: 'views', type: 'integer'),
                new Column(name: 'views', type: 'INT', nativeType: 'int unsigned', default: '0'),
            );

            expect(new MySqlGenerator()->generateUp($diff))->toBeEmpty()
                ->and(new MySqlGenerator()->generateDown($diff))->toBeEmpty();
        });

        it('emits no MODIFY COLUMN when only the uniqueness differs', function (): void {
            $diff = mysqlModifyDiff(
                new Column(name: 'email', type: 'varchar', length: 100, unique: true),
                new Column(name: 'email', type: 'VARCHAR', length: 100),
            );

            expect(new MySqlGenerator()->generateUp($diff))->toBeEmpty();
        });

        it('omits inline UNIQUE from MODIFY COLUMN', function (): void {
            $statements = new MySqlGenerator()->generateUp(mysqlModifyDiff(
                new Column(name: 'email', type: 'varchar', length: 100, nullable: true, unique: true),
                new Column(name: 'email', type: 'VARCHAR', length: 100, unique: true),
            ));

            expect($statements)->toBe(['ALTER TABLE `posts` MODIFY COLUMN `email` VARCHAR(100) NULL']);
        });

        it('keeps DECIMAL precision and UNSIGNED when the entity does not redefine the type', function (): void {
            $statements = new MySqlGenerator()->generateUp(mysqlModifyDiff(
                new Column(name: 'price', type: 'decimal', nullable: true),
                new Column(name: 'price', type: 'DECIMAL', nativeType: 'decimal(12,4) unsigned'),
            ));

            expect($statements)->toBe(['ALTER TABLE `posts` MODIFY COLUMN `price` decimal(12,4) unsigned NULL']);
        });

        it('keeps the collation and ON UPDATE when the entity does not redefine the type', function (): void {
            $statements = new MySqlGenerator()->generateUp(mysqlModifyDiff(
                new Column(name: 'updated_at', type: 'timestamp', nullable: true),
                new Column(
                    name: 'updated_at',
                    type: 'TIMESTAMP',
                    default: 'CURRENT_TIMESTAMP',
                    nativeType: 'timestamp',
                    onUpdateExpression: 'CURRENT_TIMESTAMP',
                ),
            ));

            expect($statements)->toBe([
                'ALTER TABLE `posts` MODIFY COLUMN `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP '
                . 'ON UPDATE CURRENT_TIMESTAMP',
            ]);
        });

        it('emits the entity type and keeps the collation when a string column changes length', function (): void {
            $statements = new MySqlGenerator()->generateUp(mysqlModifyDiff(
                new Column(name: 'code', type: 'varchar', length: 64),
                new Column(
                    name: 'code',
                    type: 'VARCHAR',
                    length: 32,
                    nativeType: 'varchar(32)',
                    collation: 'utf8mb4_bin',
                ),
            ));

            expect($statements)->toBe([
                'ALTER TABLE `posts` MODIFY COLUMN `code` VARCHAR(64) COLLATE utf8mb4_bin NOT NULL',
            ]);
        });

        it('drops the collation when a string column becomes non-string', function (): void {
            $statements = new MySqlGenerator()->generateUp(mysqlModifyDiff(
                new Column(name: 'code', type: 'integer'),
                new Column(
                    name: 'code',
                    type: 'VARCHAR',
                    length: 32,
                    nativeType: 'varchar(32)',
                    collation: 'utf8mb4_bin',
                ),
            ));

            expect($statements)->toBe(['ALTER TABLE `posts` MODIFY COLUMN `code` INT NOT NULL']);
        });

        it('emits the entity type when the entity changes an unsigned integer to bigint', function (): void {
            $statements = new MySqlGenerator()->generateUp(mysqlModifyDiff(
                new Column(name: 'author_id', type: 'bigint'),
                new Column(name: 'author_id', type: 'INT', nativeType: 'int unsigned'),
            ));

            expect($statements)->toBe(['ALTER TABLE `posts` MODIFY COLUMN `author_id` BIGINT NOT NULL']);
        });

        it(
            'restores DECIMAL precision, UNSIGNED, collation and ON UPDATE in a down migration',
            function (): void {
                $diff = new SchemaDiff(tablesToAlter: [
                    'posts' => new TableDiff(
                        tableName: 'posts',
                        columnsToModify: [
                            'price' => new Column(name: 'price', type: 'bigint'),
                            'code' => new Column(name: 'code', type: 'text'),
                            'updated_at' => new Column(name: 'updated_at', type: 'date'),
                        ],
                        columnsToModifyFrom: [
                            'price' => new Column(
                                name: 'price',
                                type: 'DECIMAL',
                                nativeType: 'decimal(12,4) unsigned',
                            ),
                            'code' => new Column(
                                name: 'code',
                                type: 'VARCHAR',
                                length: 32,
                                nativeType: 'varchar(32)',
                                collation: 'utf8mb4_bin',
                            ),
                            'updated_at' => new Column(
                                name: 'updated_at',
                                type: 'TIMESTAMP',
                                nullable: true,
                                default: 'CURRENT_TIMESTAMP',
                                nativeType: 'timestamp',
                                onUpdateExpression: 'CURRENT_TIMESTAMP',
                            ),
                        ],
                    ),
                ]);

                expect(new MySqlGenerator()->generateDown($diff))->toBe([
                    'ALTER TABLE `posts` MODIFY COLUMN `price` decimal(12,4) unsigned NOT NULL',
                    'ALTER TABLE `posts` MODIFY COLUMN `code` varchar(32) COLLATE utf8mb4_bin NOT NULL',
                    'ALTER TABLE `posts` MODIFY COLUMN `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP '
                    . 'ON UPDATE CURRENT_TIMESTAMP',
                ]);
            },
        );

        it('restores a native enum and binary column in a down migration', function (): void {
            $diff = new SchemaDiff(tablesToAlter: [
                'posts' => new TableDiff(
                    tableName: 'posts',
                    columnsToModify: [
                        'status' => new Column(name: 'status', type: 'text'),
                        'hash' => new Column(name: 'hash', type: 'text'),
                    ],
                    columnsToModifyFrom: [
                        'status' => new Column(name: 'status', type: 'ENUM', nativeType: "enum('a','b')"),
                        'hash' => new Column(name: 'hash', type: 'BINARY', length: 16, nativeType: 'binary(16)'),
                    ],
                ),
            ]);

            expect(new MySqlGenerator()->generateDown($diff))->toBe([
                "ALTER TABLE `posts` MODIFY COLUMN `status` enum('a','b') NOT NULL",
                'ALTER TABLE `posts` MODIFY COLUMN `hash` binary(16) NOT NULL',
            ]);
        });

        it('does not inherit a TEXT length when a column becomes a string without a length', function (): void {
            $statements = new MySqlGenerator()->generateUp(mysqlModifyDiff(
                new Column(name: 'body', type: 'varchar'),
                new Column(name: 'body', type: 'TEXT', length: 65535, nativeType: 'text'),
            ));

            expect($statements)->toBe(['ALTER TABLE `posts` MODIFY COLUMN `body` VARCHAR(255) NOT NULL']);
        });

        it('drops a kept default the new type cannot hold', function (): void {
            $diff = mysqlModifyDiff(
                new Column(name: 'title', type: 'text'),
                new Column(
                    name: 'title',
                    type: 'VARCHAR',
                    length: 500,
                    default: 'untitled',
                    nativeType: 'varchar(500)',
                ),
            );

            expect(new MySqlGenerator()->generateUp($diff))
                ->toBe(['ALTER TABLE `posts` MODIFY COLUMN `title` TEXT NOT NULL'])
                ->and(new MySqlGenerator()->generateDown($diff))
                ->toBe(["ALTER TABLE `posts` MODIFY COLUMN `title` varchar(500) NOT NULL DEFAULT 'untitled'"]);
        });

        it('keeps inline UNIQUE in CREATE TABLE and ADD COLUMN', function (): void {
            $column = new Column(name: 'email', type: 'varchar', length: 100, unique: true);

            expect(new MySqlGenerator()->generateAddColumn('posts', $column))
                ->toBe('ALTER TABLE `posts` ADD COLUMN `email` VARCHAR(100) NOT NULL UNIQUE')
                ->and(new MySqlGenerator()->generateCreateTable(new Table(name: 'posts', columns: [$column])))
                ->toContain('`email` VARCHAR(100) NOT NULL UNIQUE');
        });

        it('keeps a native timestamp when the entity declares datetime', function (): void {
            $statements = new MySqlGenerator()->generateUp(mysqlModifyDiff(
                new Column(name: 'seen_at', type: 'datetime', nullable: true),
                new Column(name: 'seen_at', type: 'TIMESTAMP', nativeType: 'timestamp(3)'),
            ));

            expect($statements)->toBe(['ALTER TABLE `posts` MODIFY COLUMN `seen_at` timestamp(3) NULL']);
        });

        it('keeps a native ENUM when the entity declares an enum column', function (): void {
            $statements = new MySqlGenerator()->generateUp(mysqlModifyDiff(
                new Column(name: 'status', type: 'enum', nullable: true),
                new Column(name: 'status', type: 'ENUM', nativeType: "enum('draft','live')"),
            ));

            expect($statements)->toBe(["ALTER TABLE `posts` MODIFY COLUMN `status` enum('draft','live') NULL"]);
        });
    });
});

/**
 * A schema diff that modifies one column of the posts table.
 */
function mysqlModifyDiff(
    Column $column,
    Column $previous,
): SchemaDiff {
    return new SchemaDiff(tablesToAlter: [
        'posts' => new TableDiff(
            tableName: 'posts',
            columnsToModify: [$column->name => $column],
            columnsToModifyFrom: [$column->name => $previous],
        ),
    ]);
}
