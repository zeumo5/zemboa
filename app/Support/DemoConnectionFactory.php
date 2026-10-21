<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Connectors\ConnectionFactory;
use RuntimeException;

// Registered exclusively by bootstrap/demo.php, never by the normal application.
final class DemoConnectionFactory extends ConnectionFactory
{
    public function createConnector(array $config)
    {
        DemoDatabase::assertConfiguration();
        DemoDatabase::assertConnectionConfiguration($config, true);
        DemoDatabase::assertPath(true);

        return parent::createConnector($config);
    }

    public function make(array $config, $name = null)
    {
        if ($name !== 'sqlite_demo') {
            throw new RuntimeException('Demo refused: alternative/dynamic connection.');
        }
        DemoDatabase::assertConfiguration();
        DemoDatabase::assertConnectionConfiguration($config, true);
        DemoDatabase::assertPath(true);

        return parent::make($config, $name);
    }
}
