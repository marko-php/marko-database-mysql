<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Connection;

use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Exceptions\QueryException;
use Marko\Database\MySql\Exceptions\ServerVersionException;

/**
 * Which server the shared connection talks to, MySQL or MariaDB, for SQL
 * whose syntax differs between them.
 *
 * The version is read with SELECT VERSION() the first time it is asked for and
 * kept for the lifetime of this object. A MySqlConnection owns one
 * (MySqlConnection::server(), which supportsReturning() asks too), and the
 * container shares that same instance, so the version is read once per
 * connection. It goes through ConnectionInterface, so it also works through a
 * decorator such as ReadWriteConnection, which the container gives its own: a
 * replica runs the same server type as its primary.
 */
class MySqlServer
{
    private ?MySqlServerVersion $version = null;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    /**
     * @throws QueryException|ServerVersionException
     */
    public function version(): MySqlServerVersion
    {
        return $this->version ??= MySqlServerVersion::fromString(
            (string) ($this->connection->query('SELECT VERSION() AS version')[0]['version'] ?? ''),
        );
    }

    /**
     * @throws QueryException|ServerVersionException
     */
    public function isMariaDb(): bool
    {
        return $this->version()->isMariaDb;
    }
}
