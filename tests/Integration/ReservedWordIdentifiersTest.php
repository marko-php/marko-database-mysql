<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Integration;

use Marko\Database\Attributes\Column;
use Marko\Database\Attributes\Table;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Diff\SchemaDiff;
use Marko\Database\Entity\Entity;
use Marko\Database\Entity\EntityHydrator;
use Marko\Database\Entity\EntityMetadataFactory;
use Marko\Database\Entity\SchemaBuilder;
use Marko\Database\Migration\DataMigration;
use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\MySql\Sql\MySqlGenerator;
use Marko\Database\MySql\Sql\MySqlIdentifier;
use Marko\Database\MySql\Tests\Fixtures\IntegrationDatabase;
use Marko\Database\Repository\Repository;
use Marko\Database\Testing\DatabaseTestHelper;

/*
 * Reserved-word and delimiter identifiers against a real MySQL server (CI also runs this file against MariaDB).
 * Tables are created from entities through SchemaBuilder and MySqlGenerator, as db:migrate creates them, and
 * every statement after that comes from Repository, DataMigration or DatabaseTestHelper, so a name any of them
 * forgets to quote fails here with a syntax error. Set MARKO_TEST_MYSQL_HOST (and optionally _PORT, _DATABASE,
 * _USERNAME, _PASSWORD) to enable; the tests skip otherwise. The tests create and drop the ident_settings and
 * ident`quoted tables.
 *
 * Settings come from tests/Fixtures/IntegrationDatabase. With MARKO_INTEGRATION_REQUIRED set (CI), a missing
 * host fails instead of skipping. Part of the integration-services group.
 */

#[Table('ident_settings')]
class MySqlReservedWordSetting extends Entity
{
    #[Column(primaryKey: true, autoIncrement: true)]
    public ?int $id = null;

    #[Column(unique: true)]
    public string $key = '';

    #[Column]
    public string $group = '';

    #[Column]
    public int $order = 0;
}

/**
 * @extends Repository<MySqlReservedWordSetting>
 */
class MySqlReservedWordSettingRepository extends Repository
{
    protected const string ENTITY_CLASS = MySqlReservedWordSetting::class;
}

#[Table('ident`quoted')]
class MySqlDelimiterNamedRow extends Entity
{
    #[Column(primaryKey: true, autoIncrement: true)]
    public ?int $id = null;

    #[Column(name: 'la`bel', unique: true)]
    public string $label = '';
}

/**
 * @extends Repository<MySqlDelimiterNamedRow>
 */
class MySqlDelimiterNamedRowRepository extends Repository
{
    protected const string ENTITY_CLASS = MySqlDelimiterNamedRow::class;
}

/**
 * A data migration exposing its insert/update/delete helpers.
 */
class MySqlReservedWordDataMigration extends DataMigration
{
    public function up(ConnectionInterface $connection): void {}

    public function down(ConnectionInterface $connection): void {}

    public function callInsert(
        ConnectionInterface $connection,
        string $table,
        array $data,
    ): int {
        return $this->insert($connection, $table, $data);
    }

    public function callUpdate(
        ConnectionInterface $connection,
        string $table,
        array $data,
        array $where,
    ): int {
        return $this->update($connection, $table, $data, $where);
    }

    public function callDelete(
        ConnectionInterface $connection,
        string $table,
        array $where,
    ): int {
        return $this->delete($connection, $table, $where);
    }
}

function mysqlReservedWordSetting(
    string $key,
    string $group,
    int $order,
): MySqlReservedWordSetting {
    $setting = new MySqlReservedWordSetting();
    $setting->key = $key;
    $setting->group = $group;
    $setting->order = $order;

    return $setting;
}

pest()->group('integration-services');

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->connection = new MySqlConnection($config);
    $this->dropTables = function (): void {
        foreach (['ident_settings', 'ident`quoted'] as $table) {
            $this->connection->execute('DROP TABLE IF EXISTS ' . MySqlIdentifier::quote($table));
        }
    };
    ($this->dropTables)();

    $metadataFactory = new EntityMetadataFactory();
    $hydrator = new EntityHydrator($metadataFactory);
    $generator = new MySqlGenerator();

    foreach ([MySqlReservedWordSetting::class, MySqlDelimiterNamedRow::class] as $entityClass) {
        $table = new SchemaBuilder()->build($metadataFactory->parse($entityClass));

        foreach ($generator->generateUp(new SchemaDiff(tablesToCreate: [$table->name => $table])) as $statement) {
            $this->connection->execute($statement);
        }
    }

    $this->settings = new MySqlReservedWordSettingRepository($this->connection, $metadataFactory, $hydrator);
    $this->delimiterRows = new MySqlDelimiterNamedRowRepository($this->connection, $metadataFactory, $hydrator);
});

afterEach(function (): void {
    if (isset($this->connection)) {
        ($this->dropTables)();
        $this->connection->disconnect();
    }
});

describe('MySQL reserved-word identifiers', function (): void {
    it(
        'round-trips an entity with reserved-word columns through save, find, findOneBy, findBy, update, exists and delete',
        function (): void {
            $from = mysqlReservedWordSetting('from', 'mail', 1);
            $host = mysqlReservedWordSetting('host', 'smtp', 2);
            $this->settings->save($from);
            $this->settings->save($host);

            expect($this->settings->find($from->id)?->key)->toBe('from')
                ->and($this->settings->findOneBy(['key' => 'host'])?->group)->toBe('smtp')
                ->and($this->settings->findBy(['group' => 'mail', 'order' => 1])->count())->toBe(1)
                ->and($this->settings->findAll()->count())->toBe(2)
                ->and($this->settings->count())->toBe(2);

            $from->group = 'smtp';
            $from->order = 3;
            $this->settings->save($from);

            expect($this->settings->find($from->id)?->order)->toBe(3)
                ->and($this->settings->findBy(['group' => 'smtp'])->count())->toBe(2)
                ->and($this->settings->exists($from->id))->toBeTrue()
                ->and($this->settings->existsBy(['key' => 'from', 'order' => 3]))->toBeTrue();

            $this->settings->delete($from);

            expect($this->settings->exists($from->id))->toBeFalse()
                ->and($this->settings->existsBy(['group' => 'mail']))->toBeFalse();
        },
    );

    it('inserts a batch of entities with reserved-word columns', function (): void {
        $settings = [
            mysqlReservedWordSetting('a', 'batch', 1),
            mysqlReservedWordSetting('b', 'batch', 2),
            mysqlReservedWordSetting('c', 'batch', 3),
        ];

        $this->settings->insertBatch($settings);

        expect(array_map(fn (MySqlReservedWordSetting $setting): ?string => $this->settings->find(
            $setting->id,
        )?->key, $settings))->toBe(['a', 'b', 'c'])
            ->and($this->settings->findBy(['group' => 'batch'])->count())->toBe(3);
    });

    it('writes reserved-word columns through DataMigration and DatabaseTestHelper', function (): void {
        $migration = new MySqlReservedWordDataMigration();
        $helper = new DatabaseTestHelper($this->connection);

        $migration->callInsert($this->connection, 'ident_settings', [
            ['key' => 'one', 'group' => 'data', 'order' => 1],
            ['key' => 'two', 'group' => 'data', 'order' => 2],
        ]);
        $migration->callUpdate(
            $this->connection,
            'ident_settings',
            ['order' => 9],
            ['key' => 'one', 'group' => 'data'],
        );
        $migration->callDelete($this->connection, 'ident_settings', ['key' => 'two']);
        $helper->seedTable('ident_settings', [['key' => 'three', 'group' => 'seed', 'order' => 3]]);

        expect($this->settings->findOneBy(['key' => 'one'])?->order)->toBe(9)
            ->and($this->settings->existsBy(['key' => 'two']))->toBeFalse()
            ->and($helper->getTableRowCount('ident_settings'))->toBe(2);

        $helper->truncateTable('ident_settings');

        expect($helper->getTableRowCount('ident_settings'))->toBe(0);
    });

    it('creates and uses a table whose names contain the delimiter', function (): void {
        $row = new MySqlDelimiterNamedRow();
        $row->label = 'tick`tock';

        $this->delimiterRows->save($row);
        $row->label = 'tock`tick';
        $this->delimiterRows->save($row);

        expect($this->delimiterRows->find($row->id)?->label)->toBe('tock`tick')
            ->and($this->delimiterRows->findOneBy(['label' => 'tock`tick'])?->id)->toBe($row->id)
            ->and($this->delimiterRows->count())->toBe(1);
    });
});
