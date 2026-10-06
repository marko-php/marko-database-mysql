<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Tests\Query;

use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\MySql\Connection\MySqlServer;
use Marko\Database\MySql\Query\MySqlQueryBuilder;
use Marko\Database\MySql\Query\MySqlQueryBuilderFactory;
use Marko\Database\Query\QueryBuilderFactoryInterface;
use Marko\Database\Query\QueryBuilderInterface;
use ReflectionClass;
use ReflectionProperty;

describe('MySqlQueryBuilderFactory', function (): void {
    it('implements QueryBuilderFactoryInterface', function (): void {
        $reflection = new ReflectionClass(MySqlQueryBuilderFactory::class);

        expect($reflection->implementsInterface(QueryBuilderFactoryInterface::class))->toBeTrue();
    });

    it('accepts ConnectionInterface via constructor', function (): void {
        $reflection = new ReflectionClass(MySqlQueryBuilderFactory::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        expect($params)->toHaveCount(2)
            ->and($params[0]->getName())->toBe('connection')
            ->and($params[0]->getType()->getName())->toBe(ConnectionInterface::class)
            ->and($params[1]->getName())->toBe('server')
            ->and($params[1]->getType()->getName())->toBe(MySqlServer::class)
            ->and($params[1]->isOptional())->toBeTrue();
    });

    it('creates MySqlQueryBuilder instances', function (): void {
        $connection = $this->createStub(ConnectionInterface::class);
        $factory = new MySqlQueryBuilderFactory($connection);

        $builder = $factory->create();

        expect($builder)->toBeInstanceOf(QueryBuilderInterface::class)
            ->and($builder)->toBeInstanceOf(MySqlQueryBuilder::class);
    });

    it('creates a new instance on each call', function (): void {
        $connection = $this->createStub(ConnectionInterface::class);
        $factory = new MySqlQueryBuilderFactory($connection);

        $builder1 = $factory->create();
        $builder2 = $factory->create();

        expect($builder1)->not->toBe($builder2);
    });

    it('passes the shared MySqlServer to every query builder it creates', function (): void {
        $connection = new RecordingTransactionalConnection();
        $server = new MySqlServer($connection);
        $factory = new MySqlQueryBuilderFactory($connection, $server);

        $first = $factory->create();
        $second = $factory->create();

        expect(new ReflectionProperty($first, 'server')->getValue($first))->toBe($server)
            ->and(new ReflectionProperty($second, 'server')->getValue($second))->toBe($server);
    });

    it('shares one server check between the builders it creates when none is given', function (): void {
        $connection = new RecordingTransactionalConnection(serverVersion: '11.8.7-MariaDB');
        $factory = new MySqlQueryBuilderFactory($connection);

        $factory->create()->table('jobs')->sharedLock()->noWait()->get();
        $factory->create()->table('jobs')->sharedLock()->skipLocked()->get();

        expect($connection->versionQueries)->toBe(1)
            ->and($connection->lastQuerySql)->toBe('SELECT * FROM `jobs` LOCK IN SHARE MODE SKIP LOCKED');
    });
});
