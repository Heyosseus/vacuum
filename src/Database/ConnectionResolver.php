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

    /**
     * What the connection Vacuum inspects is called, without requiring that it can
     * be inspected.
     *
     * resolve() answers "give me a connection I can promise things about" and throws
     * when it cannot. Naming one is a different question, and the callers that only
     * want the name are frequently the ones reporting that resolve() just failed —
     * a page that has to name the broken connection cannot be the page that rethrows
     * trying to.
     */
    public function name(): string
    {
        $name = $this->config->get('vacuum.connection');

        return is_string($name) && $name !== ''
            ? $name
            : $this->manager->getDefaultConnection();
    }
}
