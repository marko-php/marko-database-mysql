<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Exceptions;

use JsonException;
use Marko\Core\Exceptions\MarkoException;
use PDOException;

class ConnectionException extends MarkoException
{
    public static function connectionFailed(
        string $host,
        int $port,
        string $database,
        PDOException $previous,
    ): self {
        return new self(
            message: "Failed to connect to MySQL database '$database' at $host:$port",
            context: "While establishing database connection: {$previous->getMessage()}",
            suggestion: 'Verify MySQL is running and credentials are correct. Check host, port, database name, username, and password.',
            previous: $previous,
        );
    }

    public static function unknownTimezone(
        string $timezone,
        PDOException $previous,
    ): self {
        return new self(
            message: "MySQL does not know the time zone '$timezone' that database.timezone pins the session to",
            context: "While setting the session time zone on connect: {$previous->getMessage()}",
            suggestion: 'Load the time zone tables into the server (mysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root mysql, '
                . "or mariadb-tzinfo-to-sql on MariaDB), or set 'timezone' in config/database.php to 'UTC' or a fixed offset "
                . "such as '+00:00', which need no time zone tables.",
            previous: $previous,
        );
    }

    public static function invalidArrayBinding(
        int|string $parameter,
        JsonException $previous,
    ): self {
        return new self(
            message: "Failed to JSON-encode array bound to parameter '$parameter'",
            context: $previous->getMessage(),
            suggestion: 'Ensure array values are JSON-encodable (no resources, recursive references, NAN, or INF)',
            previous: $previous,
        );
    }
}
