<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use SQLite3;

final class DemoSuperAdminProvisioner
{
    public const EMAIL = 'super-admin.demo@example.test';

    public function create(Connection $connection, #[\SensitiveParameter] string $password, Closure $backup): User
    {
        if (app()->environment('testing')) {
            if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:'
                || config('database.default') !== $connection->getName()
                || array_keys(config('database.connections')) !== [$connection->getName()]) {
                throw new RuntimeException('Only isolated in-memory tests allowed.');
            }
        } else {
            DemoDatabase::assertIsolated();
            if ($connection !== app('db')->connection('sqlite_demo')) {
                throw new RuntimeException('Unexpected provisioning connection.');
            }
        }
        Validator::make(['password' => $password], ['password' => ['required', 'string', 'min:12', 'max:1024']])->validate();
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('Provisioning requires its own transaction.');
        }

        return $connection->transaction(function () use ($connection, $password, $backup): User {
            // PDO SQLite on PHP 8.3 ignores transaction_mode. Acquire the writer lock without changing rows.
            $connection->statement('UPDATE roles SET code = code WHERE 0');
            if ($connection->select('PRAGMA integrity_check')[0]->integrity_check !== 'ok'
                || $connection->select('PRAGMA foreign_key_check') !== []) {
                throw new RuntimeException('Database integrity required.');
            }
            $role = Role::on($connection->getName())->where('code', 'SUPER_ADMIN')->sole();
            if (User::on($connection->getName())->whereHas('roles', fn ($query) => $query->where('code', 'SUPER_ADMIN'))->exists()
                || User::on($connection->getName())->whereRaw('LOWER(email) = ?', [self::EMAIL])->exists()) {
                throw new RuntimeException('Existing administrator or identity collision: nothing promoted.');
            }
            $before = $this->fingerprint($connection);
            $backup();
            $user = new User;
            $user->setConnection($connection->getName());
            $user->forceFill([
                'name' => 'DEMO — Super administrateur ZEMBOA', 'email' => self::EMAIL,
                'password' => $password, 'status' => 'ACTIVE', 'is_buyer' => false,
                'store_id' => null, 'phone' => null,
            ])->save();
            $user->roles()->attach($role->getKey());
            $user->refresh();
            if (! $user->canUseSuperAdminAccount() || $user->roles()->count() !== 1
                || ! Hash::check($password, $user->password)
                || $before !== $this->fingerprint($connection, $user->getKey())) {
                throw new RuntimeException('Provisioning invariants failed; transaction rolled back.');
            }

            return $user;
        }, 1);
    }

    private function fingerprint(Connection $connection, ?int $newUser = null): array
    {
        $result = [];
        foreach ($connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $query = $connection->table($table->name);
            if ($newUser !== null && in_array($table->name, ['users', 'role_user'], true)) {
                $query->where($table->name === 'users' ? 'id' : 'user_id', '!=', $newUser);
            }
            $hashes = [];
            foreach ($query->cursor() as $row) {
                $hashes[] = hash('sha256', serialize($row));
            }
            sort($hashes);
            $result[$table->name] = hash('sha256', implode('', $hashes));
        }

        return $result;
    }

    public static function backup(SQLite3 $source, SQLite3 $destination): void
    {
        $destination->enableExceptions(true);
        $source->enableExceptions(true);
        if (! $source->backup($destination) || $destination->querySingle('PRAGMA integrity_check') !== 'ok') {
            throw new RuntimeException('SQLite backup verification failed.');
        }
        $rows = $destination->query('PRAGMA foreign_key_check');
        if ($rows->fetchArray(SQLITE3_NUM) !== false) {
            throw new RuntimeException('SQLite backup foreign keys invalid.');
        }
    }
}
