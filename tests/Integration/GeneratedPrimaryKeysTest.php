<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Integration;

use Marko\Database\Attributes\Column;
use Marko\Database\Attributes\Table;
use Marko\Database\Entity\Entity;
use Marko\Database\Entity\EntityHydrator;
use Marko\Database\Entity\EntityMetadataFactory;
use Marko\Database\Exceptions\RepositoryException;
use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\MySql\Tests\Fixtures\IntegrationDatabase;
use Marko\Database\Repository\Repository;

/*
 * Database-generated primary keys against a real server. MariaDB 10.5+ has
 * INSERT ... RETURNING, so supportsReturning() is true there and the
 * repository reads generated and auto-increment keys back with it. MySQL has
 * no RETURNING: an unset generated key throws, and insertBatch() works out
 * auto-increment ids from LAST_INSERT_ID(). CI runs this file against MySQL
 * 8.4, MariaDB 11.8 and MariaDB 10.11. Set MARKO_TEST_MYSQL_HOST (and
 * optionally MARKO_TEST_MYSQL_PORT, _DATABASE, _USERNAME, _PASSWORD) to
 * enable; the tests skip otherwise. The tests create and drop the
 * generated_key_tokens and generated_key_counters tables.
 *
 * Settings come from tests/Fixtures/IntegrationDatabase. With
 * MARKO_INTEGRATION_REQUIRED set (CI), a missing host fails instead of
 * skipping. Part of the integration-services group.
 */

#[Table('generated_key_tokens')]
class MySqlGeneratedKeyToken extends Entity
{
    #[Column(primaryKey: true, type: 'uuid', default: 'UUID()', generated: true)]
    public string $id;

    #[Column]
    public string $label = '';
}

/**
 * @extends Repository<MySqlGeneratedKeyToken>
 */
class MySqlGeneratedKeyTokenRepository extends Repository
{
    protected const string ENTITY_CLASS = MySqlGeneratedKeyToken::class;
}

#[Table('generated_key_counters')]
class MySqlGeneratedKeyCounter extends Entity
{
    #[Column(primaryKey: true, autoIncrement: true)]
    public ?int $id = null;

    #[Column]
    public string $label = '';
}

/**
 * @extends Repository<MySqlGeneratedKeyCounter>
 */
class MySqlGeneratedKeyCounterRepository extends Repository
{
    protected const string ENTITY_CLASS = MySqlGeneratedKeyCounter::class;
}

/**
 * @return list<MySqlGeneratedKeyToken>
 */
function mysqlGeneratedKeyTokens(
    string ...$labels,
): array {
    return array_map(function (string $label): MySqlGeneratedKeyToken {
        $token = new MySqlGeneratedKeyToken();
        $token->label = $label;

        return $token;
    }, array_values($labels));
}

/**
 * @return list<MySqlGeneratedKeyCounter>
 */
function mysqlGeneratedKeyCounters(
    string ...$labels,
): array {
    return array_map(function (string $label): MySqlGeneratedKeyCounter {
        $counter = new MySqlGeneratedKeyCounter();
        $counter->label = $label;

        return $counter;
    }, array_values($labels));
}

pest()->group('integration-services');

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->connection = new MySqlConnection($config);
    $this->isMariaDb = IntegrationDatabase::isMariaDb($this->connection);
    $this->connection->execute('DROP TABLE IF EXISTS generated_key_tokens');
    $this->connection->execute('DROP TABLE IF EXISTS generated_key_counters');
    $this->connection->execute(
        'CREATE TABLE generated_key_tokens (id CHAR(36) PRIMARY KEY DEFAULT (UUID()), label VARCHAR(255) NOT NULL)',
    );
    $this->connection->execute(
        'CREATE TABLE generated_key_counters (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(255) NOT NULL)',
    );
    $metadataFactory = new EntityMetadataFactory();
    $hydrator = new EntityHydrator($metadataFactory);
    $this->repository = new MySqlGeneratedKeyTokenRepository($this->connection, $metadataFactory, $hydrator);
    $this->counters = new MySqlGeneratedKeyCounterRepository($this->connection, $metadataFactory, $hydrator);
});

afterEach(function (): void {
    if (isset($this->connection)) {
        $this->connection->execute('DROP TABLE IF EXISTS generated_key_tokens');
        $this->connection->execute('DROP TABLE IF EXISTS generated_key_counters');
        $this->connection->disconnect();
    }
});

it('reports RETURNING support for the connected server', function (): void {
    // Every MariaDB CI runs (10.11, 11.8) is 10.5 or later.
    expect($this->connection->supportsReturning())->toBe($this->isMariaDb);
});

it('reads a generated key back on save on MariaDB', function (): void {
    if (!$this->isMariaDb) {
        $this->markTestSkipped('MySQL has no INSERT ... RETURNING');
    }

    $token = new MySqlGeneratedKeyToken();
    $token->label = 'api';

    $this->repository->save($token);

    $found = $this->repository->find($token->id);

    expect($token->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and($found?->label)->toBe('api');
});

it('reads each generated key back in insert order on insertBatch on MariaDB', function (): void {
    if (!$this->isMariaDb) {
        $this->markTestSkipped('MySQL has no INSERT ... RETURNING');
    }

    $tokens = mysqlGeneratedKeyTokens('first', 'second', 'third');

    $this->repository->insertBatch($tokens);

    $ids = array_map(fn (MySqlGeneratedKeyToken $token): string => $token->id, $tokens);
    $labels = array_map(
        fn (MySqlGeneratedKeyToken $token): ?string => $this->repository->find($token->id)?->label,
        $tokens,
    );

    expect(array_unique($ids))->toHaveCount(3)
        ->and($labels)->toBe(['first', 'second', 'third']);
});

it('reads auto-increment ids back with RETURNING on insertBatch on MariaDB', function (): void {
    if (!$this->isMariaDb) {
        $this->markTestSkipped('MySQL has no INSERT ... RETURNING');
    }

    // With a step of 5 the ids are not consecutive, so first-id-plus-offset arithmetic would assign the
    // wrong ids; RETURNING reads the exact ones.
    $this->connection->execute('SET SESSION auto_increment_increment = 5');
    $counters = mysqlGeneratedKeyCounters('first', 'second', 'third');

    $this->counters->insertBatch($counters);

    $rows = $this->connection->query('SELECT id, label FROM generated_key_counters ORDER BY id');
    $ids = array_map(fn (MySqlGeneratedKeyCounter $counter): ?int => $counter->id, $counters);

    expect($ids)->toBe(array_map(fn (array $row): int => (int) $row['id'], $rows))
        ->and(array_column($rows, 'label'))->toBe(['first', 'second', 'third'])
        ->and($ids[1] - $ids[0])->toBe(5);
});

it('throws RepositoryException when saving an unset generated key on MySQL', function (): void {
    if ($this->isMariaDb) {
        $this->markTestSkipped('MariaDB reads generated keys back with INSERT ... RETURNING');
    }

    $token = new MySqlGeneratedKeyToken();
    $token->label = 'api';

    expect(fn () => $this->repository->save($token))
        ->toThrow(RepositoryException::class, MySqlGeneratedKeyToken::class)
        ->and(fn () => $this->repository->save($token))
        ->toThrow(RepositoryException::class, "'mysql' connection cannot read a generated key back")
        ->and(fn () => $this->repository->insertBatch(mysqlGeneratedKeyTokens('first', 'second')))
        ->toThrow(RepositoryException::class, "'mysql' connection cannot read a generated key back")
        ->and($this->connection->query('SELECT COUNT(*) AS total FROM generated_key_tokens')[0]['total'])
        ->toEqual(0);
});

it('assigns consecutive auto-increment ids on insertBatch on MySQL without RETURNING', function (): void {
    if ($this->isMariaDb) {
        $this->markTestSkipped('MariaDB reads auto-increment ids back with INSERT ... RETURNING');
    }

    $counters = mysqlGeneratedKeyCounters('first', 'second', 'third');

    $this->counters->insertBatch($counters);

    $rows = $this->connection->query('SELECT id, label FROM generated_key_counters ORDER BY id');

    expect(array_map(fn (MySqlGeneratedKeyCounter $counter): ?int => $counter->id, $counters))
        ->toBe(array_map(fn (array $row): int => (int) $row['id'], $rows))
        ->and(array_column($rows, 'label'))->toBe(['first', 'second', 'third']);
});

it('saves an entity with a generated key column when the key is set in PHP', function (): void {
    $token = new MySqlGeneratedKeyToken();
    $token->id = '3b0c6f5e-9a1d-4c2e-8f7a-5d4b3c2a1e0f';
    $token->label = 'api';

    $this->repository->save($token);

    $found = $this->repository->find('3b0c6f5e-9a1d-4c2e-8f7a-5d4b3c2a1e0f');

    expect($found?->label)->toBe('api')
        ->and($token->id)->toBe('3b0c6f5e-9a1d-4c2e-8f7a-5d4b3c2a1e0f');
});
