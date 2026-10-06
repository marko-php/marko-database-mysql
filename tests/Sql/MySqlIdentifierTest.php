<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Sql;

use Marko\Database\MySql\Sql\MySqlIdentifier;

describe('MySqlIdentifier', function (): void {
    it('wraps a plain name in backticks', function (): void {
        expect(MySqlIdentifier::quote('users'))->toBe('`users`');
    });

    it('quotes each part of a table.column name', function (): void {
        expect(MySqlIdentifier::quote('users.email'))->toBe('`users`.`email`');
    });

    it('doubles an embedded backtick', function (): void {
        expect(MySqlIdentifier::quote('we`ird'))->toBe('`we``ird`')
            ->and(MySqlIdentifier::quote('a`b.c`d'))->toBe('`a``b`.`c``d`');
    });

    it('quotes a reserved word', function (): void {
        expect(MySqlIdentifier::quote('key'))->toBe('`key`')
            ->and(MySqlIdentifier::quote('group'))->toBe('`group`')
            ->and(MySqlIdentifier::quote('order'))->toBe('`order`');
    });

    it('keeps mixed case and double quotes as written', function (): void {
        expect(MySqlIdentifier::quote('createdAt'))->toBe('`createdAt`')
            ->and(MySqlIdentifier::quote('say "hi"'))->toBe('`say "hi"`');
    });
});
