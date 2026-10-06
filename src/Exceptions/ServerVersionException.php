<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Exceptions;

use Marko\Core\Exceptions\MarkoException;

class ServerVersionException extends MarkoException
{
    public static function unreadable(
        string $reported,
    ): self {
        return new self(
            message: "Could not read the MySQL server version from \"$reported\"",
            context: 'While detecting whether the server is MySQL or MariaDB from SELECT VERSION()',
            suggestion: 'Check that the connection reaches a MySQL or MariaDB server. Both report a version such as '
                . '"8.4.3" or "11.8.7-MariaDB".',
        );
    }
}
