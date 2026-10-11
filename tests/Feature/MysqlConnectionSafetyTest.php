<?php

namespace Tests\Feature;



use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MysqlConnectionSafetyTest extends TestCase
{
    public function test_mysql_uses_dedicated_test_database(): void
    {
        // Ce test est réservé à notre configuration MySQL.
        // Les tests habituels SQLite peuvent continuer.
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped(
                'Vérification réservée aux tests MySQL.'
            );
        }

        // Vérifier le moteur de base de données.
        $this->assertSame(
            'mysql',
            config('database.default')
        );

        // Vérifier la base configurée dans Laravel.
        $this->assertSame(
            'zemboa_concurrency_test',
            config('database.connections.mysql.database')
        );

        // Vérifier l'utilisateur configuré.
        $this->assertSame(
            'zemboa_test',
            config('database.connections.mysql.username')
        );

        // Interroger la connexion MySQL réellement utilisée.
        $connection = DB::selectOne(
            'SELECT DATABASE() AS database_name,
                    CURRENT_USER() AS mysql_account'
        );

        // Vérifier la base réellement connectée.
        $this->assertSame(
            'zemboa_concurrency_test',
            $connection->database_name
        );

        // Vérifier les droits réellement utilisés.
        $this->assertSame(
            'zemboa_test@127.0.0.1',
            $connection->mysql_account
        );
    }
}
