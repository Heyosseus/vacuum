<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Database;

use Heyosseus\Vacuum\Exceptions\UnsupportedDriver;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

final readonly class ConnectionResolver
{
    private const string DRIVER = 'pgsql';

    public function __construct(
        private DatabaseManager $manager,
        private Repository $config,
    ) {}

    /**
     * The connection Vacuum inspects: the one named in the configuration, or
     * the application's default when none is named.
     *
     * @throws UnsupportedDriver
     */
    public function resolve(): Connection
    {
        $name = $this->config->get('vacuum.connection');

        $connection = $this->manager->connection(is_string($name) ? $name : null);

        if ($connection->getDriverName() !== self::DRIVER) {
            // A connection built outside the config file carries no name.
            throw UnsupportedDriver::for($connection->getName() ?? 'unnamed', $connection->getDriverName());
        }

        return $connection;
    }
}
