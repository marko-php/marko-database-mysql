<?php

declare(strict_types=1);

use Marko\Core\Container\ContainerInterface;
use Marko\Database\Config\DatabaseConfig;
use Marko\Database\Connection\ConnectionFactoryInterface;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\TransactionInterface;
use Marko\Database\Exceptions\TransactionException;
use Marko\Database\Diff\SqlGeneratorInterface;
use Marko\Database\Introspection\IntrospectorInterface;
use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\MySql\Connection\MySqlConnectionFactory;
use Marko\Database\MySql\Introspection\MySqlIntrospector;
use Marko\Database\MySql\Query\MySqlQueryBuilder;
use Marko\Database\MySql\Query\MySqlQueryBuilderFactory;
use Marko\Database\MySql\Sql\MySqlGenerator;
use Marko\Database\Query\QueryBuilderFactoryInterface;
use Marko\Database\Query\QueryBuilderInterface;

// Marko-specific configuration for this module.
// Name and version come from composer.json.

return [
    'bindings' => [
        ConnectionInterface::class => MySqlConnection::class,
        ConnectionFactoryInterface::class => MySqlConnectionFactory::class,
        IntrospectorInterface::class => function (ContainerInterface $container): IntrospectorInterface {
            $config = $container->get(DatabaseConfig::class);

            return new MySqlIntrospector(
                $container->get(ConnectionInterface::class),
                $config->database,
            );
        },
        SqlGeneratorInterface::class => MySqlGenerator::class,
        QueryBuilderInterface::class => MySqlQueryBuilder::class,
        QueryBuilderFactoryInterface::class => MySqlQueryBuilderFactory::class,
        // Transactions run on the shared connection, so they cover every
        // repository, query builder and service that injects ConnectionInterface.
        TransactionInterface::class => static function (ContainerInterface $container): TransactionInterface {
            $connection = $container->get(ConnectionInterface::class);

            if (!$connection instanceof TransactionInterface) {
                throw TransactionException::connectionDoesNotSupportTransactions($connection::class);
            }

            return $connection;
        },
    ],
    // One connection (one PDO handle) per container, shared by every consumer.
    'singletons' => [
        ConnectionInterface::class,
    ],
];
