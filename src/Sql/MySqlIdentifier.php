<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Sql;

use Marko\Database\Query\IdentifierValidator;

/**
 * The one identifier-quoting rule for MySQL and MariaDB.
 *
 * MySqlGenerator, MySqlQueryBuilder, MySqlIntrospector and MySqlConnection::quoteIdentifier() all quote through
 * it, so a name is quoted the same way whichever code path emits it.
 */
class MySqlIdentifier
{
    private const string DELIMITER = '`';

    /**
     * Quote a table or column name with backticks.
     *
     * A `table.column` name has each part quoted. An embedded backtick is doubled, so a name cannot break out
     * of its delimiters. Reserved words and mixed case are safe once quoted.
     */
    public static function quote(
        string $identifier,
    ): string {
        return implode('.', array_map(
            static fn (string $part): string => self::DELIMITER
                . IdentifierValidator::escapeDelimiter($part, self::DELIMITER)
                . self::DELIMITER,
            explode('.', $identifier),
        ));
    }
}
