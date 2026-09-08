<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * DBAL middleware that applies SQLite PRAGMAs whenever a new connection is
 * opened. It does nothing on other platforms, keeping the code portable.
 *
 *   - journal_mode = WAL: improves read/write concurrency;
 *   - foreign_keys = ON: enforces foreign key constraints;
 *   - busy_timeout = 5000: waits when the database is locked;
 *   - synchronous = NORMAL: balances durability and performance.
 */
#[AsMiddleware(priority: 100)]
final class SqlitePragmasMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends Middleware\AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Driver\Connection
            {
                $connection = parent::connect($params);

                // getDatabasePlatform() on the wrapped driver uses the
                // connection as its ServerVersionProvider. This detects SQLite
                // without opening an additional connection.
                $platform = $this->getDatabasePlatform($connection);
                if (!$platform instanceof SQLitePlatform) {
                    return $connection;
                }

                $connection->exec('PRAGMA journal_mode = WAL');
                $connection->exec('PRAGMA foreign_keys = ON');
                $connection->exec('PRAGMA busy_timeout = 5000');
                $connection->exec('PRAGMA synchronous = NORMAL');

                return $connection;
            }
        };
    }
}
