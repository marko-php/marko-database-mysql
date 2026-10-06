<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Introspection;

use Closure;
use Marko\Database\Exceptions\MigrationException;
use Marko\Database\Exceptions\QueryException;
use Marko\Database\Introspection\ExpressionDefaultMatcherInterface;
use Marko\Database\MySql\Introspection\MySqlIntrospector;
use Marko\Database\Schema\Expression;
use RuntimeException;

/**
 * A connection whose real events.label column (varchar(40)) reports $storedDefault in information_schema and
 * whose probe table reports $probeDefault from SHOW COLUMNS.
 */
function mysqlProbeConnection(
    string $storedDefault,
    string $probeDefault,
    string $version = '8.4.5',
    ?Closure $onExecute = null,
): ProbeRecordingConnection {
    return new ProbeRecordingConnection(
        fn (string $sql): array => match (true) {
            str_contains($sql, 'VERSION()') => [['version' => $version]],
            str_starts_with($sql, 'SHOW COLUMNS') => [['Field' => 'probe', 'Default' => $probeDefault]],
            default => [['COLUMN_TYPE' => 'varchar(40)', 'COLUMN_DEFAULT' => $storedDefault]],
        },
        $onExecute,
    );
}

function concatExpression(): Expression
{
    return new Expression("CONCAT('a', 'b')");
}

describe('MySqlIntrospector expression default matching', function (): void {
    it('implements ExpressionDefaultMatcherInterface', function (): void {
        expect(new MySqlIntrospector(mysqlProbeConnection('', ''), 'app'))
            ->toBeInstanceOf(ExpressionDefaultMatcherInterface::class);
    });

    it('reports a match when the temporary table stores the same default as the column', function (): void {
        $connection = mysqlProbeConnection('(now() + interval 1 day)', '((now() + interval 1 day))');

        $matches = new MySqlIntrospector($connection, 'app')
            ->matchesStoredDefault('events', 'label', new Expression('CURRENT_TIMESTAMP + INTERVAL 1 DAY'));

        expect($matches)->toBeTrue()
            ->and($connection->log)->toContain(
                'CREATE TEMPORARY TABLE `marko_default_probe` (`probe` varchar(40) NULL DEFAULT '
                . '(CURRENT_TIMESTAMP + INTERVAL 1 DAY))',
            );
    });

    it('unescapes the quotes MySQL escapes in information_schema before comparing', function (): void {
        $connection = mysqlProbeConnection(
            "concat(_utf8mb4\\'it\\\\\\'s\\',_utf8mb4\\'b\\')",
            "(concat(_utf8mb4'it\\'s',_utf8mb4'b'))",
        );

        expect(
            new MySqlIntrospector($connection, 'app')
                ->matchesStoredDefault('events', 'label', new Expression("CONCAT('it''s', 'b')")),
        )->toBeTrue();
    });

    it('does not unescape the stored default on MariaDB', function (): void {
        $connection = mysqlProbeConnection("concat('it\\'s','b')", "concat('it\\'s','b')", '11.8.2-MariaDB');

        expect(
            new MySqlIntrospector($connection, 'app')
                ->matchesStoredDefault('events', 'label', new Expression("CONCAT('it''s', 'b')")),
        )->toBeTrue();
    });

    it('reports no match when the temporary table stores a different default', function (): void {
        $connection = mysqlProbeConnection(
            "concat(_utf8mb4\\'a\\',_utf8mb4\\'b\\')",
            "(concat(_utf8mb4'a',_utf8mb4'c'))",
        );

        expect(
            new MySqlIntrospector($connection, 'app')
                ->matchesStoredDefault('events', 'label', new Expression("CONCAT('a', 'c')")),
        )->toBeFalse();
    });

    it('keeps a CURRENT_TIMESTAMP family expression bare in the probe', function (): void {
        $connection = mysqlProbeConnection('CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP');

        new MySqlIntrospector($connection, 'app')
            ->matchesStoredDefault('events', 'label', new Expression('CURRENT_TIMESTAMP(3)'));

        expect($connection->log)->toContain(
            'CREATE TEMPORARY TABLE `marko_default_probe` (`probe` varchar(40) NULL DEFAULT CURRENT_TIMESTAMP(3))',
        );
    });

    it('drops a leftover probe table before creating it', function (): void {
        $connection = mysqlProbeConnection("concat(_utf8mb4\\'a\\')", "(concat(_utf8mb4'a'))");

        new MySqlIntrospector($connection, 'app')->matchesStoredDefault('events', 'label', concatExpression());

        $create = array_find_key($connection->log, fn (string $sql): bool => str_starts_with($sql, 'CREATE'));

        expect($connection->log[$create - 1])->toBe('DROP TEMPORARY TABLE IF EXISTS `marko_default_probe`');
    });

    it('drops the temporary table after probing', function (): void {
        $connection = mysqlProbeConnection("concat(_utf8mb4\\'a\\')", "(concat(_utf8mb4'a'))");

        new MySqlIntrospector($connection, 'app')->matchesStoredDefault('events', 'label', concatExpression());

        expect(array_last($connection->log))->toBe('DROP TEMPORARY TABLE IF EXISTS `marko_default_probe`');
    });

    it(
        'throws a MigrationException naming the column and expression when MySQL rejects the expression',
        function (): void {
            $connection = mysqlProbeConnection(
                "concat(_utf8mb4\\'a\\')",
                '',
                onExecute: function (string $sql): void {
                    if (str_starts_with($sql, 'CREATE')) {
                        throw new QueryException('Query failed: You have an error in your SQL syntax', $sql);
                    }
                },
            );

            expect(
                fn () => new MySqlIntrospector($connection, 'app')
                    ->matchesStoredDefault('events', 'label', new Expression("CONCAT('a',")),
            )->toThrow(
                MigrationException::class,
                "The database rejected the default expression \"CONCAT('a',\" of column 'events.label'",
            )->and(array_last($connection->log))->toBe('DROP TEMPORARY TABLE IF EXISTS `marko_default_probe`');
        },
    );

    it('lets a connection failure propagate without reporting a rejected expression', function (): void {
        $connection = mysqlProbeConnection(
            "concat(_utf8mb4\\'a\\')",
            '',
            onExecute: function (string $sql): void {
                if (str_starts_with($sql, 'CREATE')) {
                    throw new RuntimeException('MySQL server has gone away');
                }
            },
        );

        expect(
            fn () => new MySqlIntrospector($connection, 'app')
                ->matchesStoredDefault('events', 'label', concatExpression()),
        )->toThrow(RuntimeException::class, 'MySQL server has gone away');
    });
});
