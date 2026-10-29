<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SQLite FK toggling must occur BEFORE its explicit DDL transaction.
    public $withinTransaction = false;

    public function up(): void
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();
        if (!in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException('Buyer identity migration requires a reviewed SQLite/MySQL driver.');
        }
        $expression = $this->identityExpression($driver);
        // Read-only preflight before any DDL. No identity is merged or rewritten.
        $identities = $connection->table('users')->selectRaw("$expression AS identity_phone");
        if ($connection->query()->fromSub($identities, 'identities')->whereNotNull('identity_phone')
            ->groupBy('identity_phone')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Conflicting historical buyer phone identities. Manual review required; no schema change applied.');
        }
        $this->alter(function () use ($expression): void {
            Schema::table('users', fn (Blueprint $table) => $table->string('email')->nullable()->change());
            Schema::table('users', function (Blueprint $table) use ($expression) {
                $table->string('buyer_phone_identity', 13)->nullable()->virtualAs($expression);
                $table->unique('buyer_phone_identity', 'users_buyer_phone_identity_unique');
            });
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->table('users')->whereNull('email')->exists()) {
            throw new RuntimeException('Cannot restore mandatory email while email-less accounts exist. Manual deployment review required.');
        }
        $this->alter(function (): void {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique('users_buyer_phone_identity_unique');
                $table->dropColumn('buyer_phone_identity');
            });
            Schema::table('users', fn (Blueprint $table) => $table->string('email')->nullable(false)->change());
        });
    }

    private function alter(callable $callback): void
    {
        $connection = Schema::getConnection();
        if ($connection->getDriverName() !== 'sqlite') { $callback(); return; }
        if ($connection->transactionLevel() !== 0 || !(bool) $connection->scalar('PRAGMA foreign_keys')) {
            throw new RuntimeException('SQLite identity DDL requires top-level execution with foreign keys enabled.');
        }
        // SQLite cannot toggle foreign_keys inside a transaction. Disabling it here
        // prevents DROP of the copied users table from cascading into role_user etc.
        Schema::disableForeignKeyConstraints();
        try {
            $connection->transaction(function () use ($callback, $connection): void {
                $callback();
                if ($connection->select('PRAGMA foreign_key_check') !== []) {
                    throw new RuntimeException('Identity migration foreign-key verification failed; transaction rolled back.');
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function identityExpression(string $driver): string
    {
        $clean = 'phone';
        foreach ([' ', '-', '(', ')', '.'] as $separator) $clean = "REPLACE($clean, '$separator', '')";
        foreach ([9, 10, 13] as $char) $clean = "REPLACE($clean, CHAR($char), '')";
        $local = $driver === 'sqlite' ? "'+237' || $clean" : "CONCAT('+237', $clean)";
        $international = $driver === 'sqlite' ? "'+' || $clean" : "CONCAT('+', $clean)";
        if ($driver === 'sqlite') {
            $suffix = str_repeat('[0-9]', 8);
            return "CASE WHEN is_buyer = 1 THEN CASE WHEN $clean GLOB '6$suffix' THEN $local WHEN $clean GLOB '2376$suffix' THEN $international WHEN $clean GLOB '+2376$suffix' THEN $clean ELSE NULL END ELSE NULL END";
        }
        // Portable deterministic scalar functions; no REGEXP dependency in generated DDL.
        $digits = implode(' AND ', array_map(fn ($i) => "ASCII(SUBSTR($clean, -$i, 1)) BETWEEN 48 AND 57", range(1, 9)));
        return "CASE WHEN is_buyer = 1 AND $digits THEN CASE WHEN LENGTH($clean) = 9 AND HEX(SUBSTR($clean, 1, 1)) = _binary'36' THEN $local WHEN LENGTH($clean) = 12 AND HEX(SUBSTR($clean, 1, 4)) = _binary'32333736' THEN $international WHEN LENGTH($clean) = 13 AND HEX(SUBSTR($clean, 1, 5)) = _binary'2B32333736' THEN $clean ELSE NULL END ELSE NULL END";
    }
};
