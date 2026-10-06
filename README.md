# marko/database-mysql

MySQL and MariaDB driver for the Marko framework database layer.

## Installation

```bash
composer require marko/database-mysql
```

This automatically installs `marko/database` (the interface package) as a dependency.

## Configuration

Create `config/database.php` with a flat array of connection details:

```php title="config/database.php"
<?php

declare(strict_types=1);

return [
    'driver' => 'mysql',
    'host' => $_ENV['DB_HOST'] ?? 'localhost',
    'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
    'database' => $_ENV['DB_DATABASE'] ?? 'marko',
    'username' => $_ENV['DB_USERNAME'] ?? 'root',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
];
```

`driver`, `host`, `port`, `database`, `username`, and `password` are all required. Set the corresponding values in your `.env` file:

```dotenv
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=marko
DB_USERNAME=root
DB_PASSWORD=secret
```

For multiple connections (for example, read replicas), use [`marko/database-readwrite`](https://marko.build/docs/packages/database-readwrite/), which adds the `connections` layout.

## Driver Notes

This driver supports **MySQL** 8.0+ and **MariaDB** 10.11+. Both are fully supported via the same driver key (`mysql`).

## Quick Example

```php
use Marko\Database\Connection\ConnectionInterface;

class MyService
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function doSomething(): void
    {
        $result = $this->connection->query('SELECT * FROM users');
    }
}
```

## Documentation

Full configuration, driver notes, and API reference: [marko/database-mysql](https://marko.build/docs/packages/database-mysql/)
