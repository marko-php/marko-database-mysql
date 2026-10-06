<?php

declare(strict_types=1);

use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\StatementInterface;
use Marko\Database\MySql\Connection\MySqlServer;
use Marko\Database\MySql\Exceptions\ServerVersionException;
use Marko\Database\MySql\Tests\Query\RecordingTransactionalConnection;

describe('MySqlServer', function (): void {
    it('reports MariaDB for a MariaDB server', function (): void {
        $server = new MySqlServer(new RecordingTransactionalConnection(serverVersion: '11.8.7-MariaDB-ubu2404'));

        expect($server->isMariaDb())->toBeTrue()
            ->and($server->version()->version)->toBe('11.8.7');
    });

    it('reports MySQL for a MySQL server', function (): void {
        $server = new MySqlServer(new RecordingTransactionalConnection(serverVersion: '8.4.3'));

        expect($server->isMariaDb())->toBeFalse()
            ->and($server->version()->version)->toBe('8.4.3');
    });

    it('queries the server version only once', function (): void {
        $connection = new RecordingTransactionalConnection(serverVersion: '5.5.5-10.11.8-MariaDB');
        $server = new MySqlServer($connection);

        $server->isMariaDb();
        $server->isMariaDb();
        $server->version();

        expect($connection->versionQueries)->toBe(1);
    });

    it('does not query the server until asked', function (): void {
        $connection = new RecordingTransactionalConnection();

        new MySqlServer($connection);

        expect($connection->versionQueries)->toBe(0);
    });

    it('detects the server through a connection decorator', function (): void {
        $inner = new RecordingTransactionalConnection(serverVersion: '11.8.7-MariaDB');
        $decorator = new readonly class ($inner) implements ConnectionInterface
        {
            public function __construct(
                private ConnectionInterface $inner,
            ) {}

            public function connect(): void {}

            public function disconnect(): void {}

            public function isConnected(): bool
            {
                return true;
            }

            public function query(
                string $sql,
                array $bindings = [],
            ): array {
                return $this->inner->query($sql, $bindings);
            }

            public function execute(
                string $sql,
                array $bindings = [],
            ): int {
                return $this->inner->execute($sql, $bindings);
            }

            public function prepare(
                string $sql,
            ): StatementInterface {
                return $this->inner->prepare($sql);
            }

            public function lastInsertId(): int
            {
                return 0;
            }

            public function driverName(): string
            {
                return 'mysql';
            }

            public function supportsReturning(): bool
            {
                return false;
            }

            public function quoteIdentifier(
                string $identifier,
            ): string {
                return $this->inner->quoteIdentifier($identifier);
            }
        };

        expect(new MySqlServer($decorator)->isMariaDb())->toBeTrue();
    });

    it('throws when the server reports no version', function (): void {
        $server = new MySqlServer(new RecordingTransactionalConnection(serverVersion: ''));

        expect(fn () => $server->isMariaDb())->toThrow(ServerVersionException::class);
    });
});
