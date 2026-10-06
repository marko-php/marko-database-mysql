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
 * Database-generated primary keys against a real MySQL server, which has no
 * INSERT ... RETURNING. CI also runs this file against MariaDB, which the
 * driver treats the same way (supportsReturning() is false). Set MARKO_TEST_MYSQL_HOST (and optionally
 * MARKO_TEST_MYSQL_PORT, _DATABASE, _USERNAME, _PASSWORD) to enable; the tests
 * skip otherwise. The tests create and drop the generated_key_tokens table.
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

pest()->group('integration-services');

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->connection = new MySqlConnection($config);
    $this->connection->execute('DROP TABLE IF EXISTS generated_key_tokens');
    $this->connection->execute(
        'CREATE TABLE generated_key_tokens (id CHAR(36) PRIMARY KEY DEFAULT (UUID()), label VARCHAR(255) NOT NULL)',
    );
    $metadataFactory = new EntityMetadataFactory();
    $this->repository = new MySqlGeneratedKeyTokenRepository(
        $this->connection,
        $metadataFactory,
        new EntityHydrator($metadataFactory),
    );
});

afterEach(function (): void {
    if (isset($this->connection)) {
        $this->connection->execute('DROP TABLE IF EXISTS generated_key_tokens');
        $this->connection->disconnect();
    }
});

it('throws RepositoryException when saving an unset generated key on MySQL', function (): void {
    $token = new MySqlGeneratedKeyToken();
    $token->label = 'api';

    expect(fn () => $this->repository->save($token))
        ->toThrow(RepositoryException::class, MySqlGeneratedKeyToken::class)
        ->and(fn () => $this->repository->save($token))
        ->toThrow(RepositoryException::class, "'mysql' connection cannot read a generated key back")
        ->and($this->connection->query('SELECT COUNT(*) AS total FROM generated_key_tokens')[0]['total'])
        ->toEqual(0);
});

it('saves an entity with a generated key column when the key is set in PHP on MySQL', function (): void {
    $token = new MySqlGeneratedKeyToken();
    $token->id = '3b0c6f5e-9a1d-4c2e-8f7a-5d4b3c2a1e0f';
    $token->label = 'api';

    $this->repository->save($token);

    $found = $this->repository->find('3b0c6f5e-9a1d-4c2e-8f7a-5d4b3c2a1e0f');

    expect($found?->label)->toBe('api')
        ->and($token->id)->toBe('3b0c6f5e-9a1d-4c2e-8f7a-5d4b3c2a1e0f');
});
