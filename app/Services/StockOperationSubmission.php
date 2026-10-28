<?php

namespace App\Services;

use App\Actions\Stock\AdjustStockIn;
use App\Actions\Stock\AdjustStockOut;
use App\Actions\Stock\ReceiveStock;
use App\Models\StockLevel;
use App\Models\User;
use App\Support\AccountDestination;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StockOperationSubmission
{
    public function ready(): void
    {
        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();
            abort_unless(in_array($driver, ['sqlite', 'mysql', 'mariadb'], true), 503, __('inventory.schema'));
            $schema = $connection->getSchemaBuilder();
            $columns = collect($schema->getColumns('stock_operation_submissions'))->keyBy('name');
            foreach (['id' => false, 'store_id' => false, 'user_id' => false, 'stock_level_id' => true, 'key_hash' => false, 'request_hash' => true, 'created_at' => false] as $name => $nullable) {
                $column = $columns->get($name);
                abort_unless($column && $column['nullable'] === $nullable && $column['generation'] === null, 503, __('inventory.schema'));
                if (in_array($name, ['id', 'store_id', 'user_id', 'stock_level_id'], true)) {
                    abort_unless($driver === 'sqlite' ? $column['type_name'] === 'integer' : preg_match('/^bigint(?:\(\d+\))? unsigned$/', strtolower($column['type'])) === 1, 503, __('inventory.schema'));
                } elseif (in_array($name, ['key_hash', 'request_hash'], true)) {
                    abort_unless($driver === 'sqlite' ? $column['type_name'] === 'varchar' : $column['type'] === 'char(64)', 503, __('inventory.schema'));
                } else {
                    abort_unless(in_array($column['type_name'], ['datetime', 'timestamp'], true), 503, __('inventory.schema'));
                }
            }
            $indexes = collect($schema->getIndexes('stock_operation_submissions'));
            abort_unless($columns['id']['auto_increment'] && $indexes->contains(fn (array $index): bool => $index['primary'] && $index['columns'] === ['id'])
                && $indexes->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['store_id', 'key_hash']), 503, __('inventory.schema'));
            $foreignKeys = collect($schema->getForeignKeys('stock_operation_submissions'));
            foreach (['store_id' => 'stores', 'user_id' => 'users', 'stock_level_id' => 'stock_levels'] as $column => $table) {
                abort_unless($foreignKeys->contains(fn (array $key): bool => $key['columns'] === [$column] && $key['foreign_table'] === $table
                    && $key['foreign_columns'] === ['id'] && $key['on_delete'] === 'restrict'
                    && in_array($key['foreign_schema'], $driver === 'sqlite' ? [null, 'main'] : [$connection->getDatabaseName()], true)), 503, __('inventory.schema'));
            }
            if ($driver === 'sqlite') {
                abort_unless((int) $connection->selectOne('PRAGMA foreign_keys')->foreign_keys === 1, 503, __('inventory.schema'));
            } else {
                $tables = $connection->table('information_schema.tables')->where('table_schema', $connection->getDatabaseName())
                    ->whereIn('table_name', ['stock_operation_submissions', 'stock_levels', 'stock_movements'])
                    ->get(['engine as stock_engine', 'table_name as stock_table'])->pluck('stock_engine', 'stock_table');
                abort_unless($tables->count() === 3 && $tables->every(fn (?string $engine): bool => strtolower($engine ?? '') === 'innodb')
                    && (int) $connection->selectOne('SELECT @@FOREIGN_KEY_CHECKS AS enabled')->enabled === 1, 503, __('inventory.schema'));
            }
        } catch (QueryException) {
            abort(503, __('inventory.schema'));
        }
    }

    private function authorize(User $actor): void
    {
        abort_unless(auth('web')->id() === $actor->id && AccountDestination::professionalIdentity($actor)
            && $actor->roles()->whereIn('code', ['STORE_OWNER', 'STORE_MANAGER'])->exists()
            && $actor->hasPermission('inventory.manage') && (int) $actor->store_id === (int) app(TenantContext::class)->storeId(), 403);
    }

    public function issue(User $actor, string $operation): string
    {
        $this->ready();
        $this->authorize($actor);
        abort_unless(in_array($operation, ['receive', 'adjust-in', 'adjust-out'], true), 404);

        $nonce = Str::random(64);
        $token = Crypt::encryptString(json_encode(['actor' => $actor->id, 'store' => $actor->store_id, 'operation' => $operation, 'nonce' => $nonce, 'expires' => now()->addHours(2)->timestamp], JSON_THROW_ON_ERROR));
        try {
            DB::table('stock_operation_submissions')->insert(['store_id' => $actor->store_id, 'user_id' => $actor->id, 'stock_level_id' => null,
                'key_hash' => hash('sha256', $nonce), 'request_hash' => null, 'created_at' => now()]);
        } catch (QueryException) {
            abort(503, __('inventory.schema'));
        }

        return $token;
    }

    public function execute(User $actor, StockLevel $stock, string $operation, int $quantity, ?string $reason, string $token): bool
    {
        $this->ready();
        $this->authorize($actor);
        Gate::forUser($actor)->authorize('manage', $stock);
        abort_unless((int) $stock->store_id === (int) $actor->store_id, 404);
        try {
            $intent = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(419, __('inventory.expired'));
        }
        abort_unless(is_array($intent) && ($intent['actor'] ?? null) === $actor->id && (int) ($intent['store'] ?? 0) === (int) $actor->store_id
            && ($intent['operation'] ?? null) === $operation && is_int($intent['expires'] ?? null) && $intent['expires'] > now()->timestamp
            && is_string($intent['nonce'] ?? null) && strlen($intent['nonce']) === 64, 419, __('inventory.expired'));
        $key = hash('sha256', $intent['nonce']);
        $fingerprint = hash('sha256', json_encode([$actor->id, $stock->id, $operation, $quantity, $reason], JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($actor, $stock, $operation, $quantity, $reason, $key, $fingerprint) {
                $submission = DB::table('stock_operation_submissions')->where('store_id', $actor->store_id)->where('user_id', $actor->id)
                    ->where('key_hash', $key)->lockForUpdate()->first();
                abort_unless($submission, 419, __('inventory.expired'));
                if ($submission->request_hash !== null) {
                    abort_unless((int) $submission->stock_level_id === (int) $stock->id && hash_equals($submission->request_hash, $fingerprint), 409, __('inventory.changed'));

                    return false;
                }
                abort_unless($submission->stock_level_id === null, 503, __('inventory.schema'));
                $current = StockLevel::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
                $action = match ($operation) {
                    'receive' => ReceiveStock::class,'adjust-in' => AdjustStockIn::class,'adjust-out' => AdjustStockOut::class,default => abort(404)
                };
                if ($operation !== 'adjust-out' && $quantity > 4294967295 - $current->physical_quantity) {
                    throw ValidationException::withMessages(['quantity' => __('inventory.error')]);
                }
                try {
                    app($action)->execute($current->product_variant_id, $quantity, $actor->id, $reason);
                } catch (ValidationException $error) {
                    if (app()->getLocale() === 'en' && isset($error->errors()['quantity'])) {
                        throw ValidationException::withMessages(['quantity' => __('inventory.error')]);
                    }

                    throw $error;
                }
                $updated = DB::table('stock_operation_submissions')->where('id', $submission->id)->where('store_id', $actor->store_id)
                    ->where('user_id', $actor->id)->whereNull('request_hash')->update(['stock_level_id' => $current->id, 'request_hash' => $fingerprint]);
                abort_unless($updated === 1, 409, __('inventory.changed'));

                return true;
            });
        } catch (QueryException) {
            abort(503, __('inventory.schema'));
        }
    }
}
