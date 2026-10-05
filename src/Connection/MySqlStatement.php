<?php

declare(strict_types=1);

namespace Marko\Database\MySql\Connection;

use Marko\Database\Connection\StatementInterface;
use Marko\Database\Exceptions\QueryException;
use PDO;
use PDOException;
use PDOStatement;

readonly class MySqlStatement implements StatementInterface
{
    public function __construct(
        private PDOStatement $statement,
        private MySqlExceptionTranslator $exceptionTranslator = new MySqlExceptionTranslator(),
    ) {}

    /**
     * @throws QueryException
     */
    public function execute(
        array $bindings = [],
    ): bool {
        try {
            return $this->statement->execute($bindings);
        } catch (PDOException $e) {
            throw $this->exceptionTranslator->translate($e, $this->statement->queryString, $bindings);
        }
    }

    public function fetchAll(): array
    {
        return $this->statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetch(): ?array
    {
        $result = $this->statement->fetch(PDO::FETCH_ASSOC);

        return $result === false ? null : $result;
    }

    public function rowCount(): int
    {
        return $this->statement->rowCount();
    }
}
