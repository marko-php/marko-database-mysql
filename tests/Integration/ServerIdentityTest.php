<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Integration;

use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\MySql\Tests\Fixtures\IntegrationDatabase;
use RuntimeException;

/*
 * The CI Integration job runs this suite twice, against MySQL and against
 * MariaDB, and sets MARKO_TEST_MYSQL_SERVER for each run. This test fails a
 * run that reached the other server (a wrong port), so the MariaDB run can
 * never pass by skipping its MariaDB-only tests.
 *
 * Settings come from tests/Fixtures/IntegrationDatabase. With
 * MARKO_INTEGRATION_REQUIRED set (CI), a missing host fails instead of
 * skipping. Part of the integration-services group.
 */

pest()->group('integration-services');

it('connects to the server MARKO_TEST_MYSQL_SERVER names', function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $connection = new MySqlConnection($config);
    $version = IntegrationDatabase::serverVersion($connection);
    $connection->disconnect();

    expect($version)->not->toBe('')
        ->and(fn () => IntegrationDatabase::assertServer($version))->not->toThrow(RuntimeException::class);
});
