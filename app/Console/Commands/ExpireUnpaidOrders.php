<?php

namespace App\Console\Commands;

use App\Actions\Orders\ExpireOrder;
use App\Models\Store;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid {--limit=100 : Maximum candidates in this run}';
    protected $description = 'Expire eligible unpaid orders (disabled until explicitly configured).';

    public function handle(ExpireOrder $expire): int
    {
        // All checks precede connection resolution, queries and cache writes.
        if (config('order_expiration.enabled') !== true) {
            $this->info('Order expiration is disabled. No database accessed.');
            return self::SUCCESS;
        }
        $connection = config('order_expiration.connection');
        $database = config('order_expiration.database');
        $store = config('order_expiration.lock_store');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (app()->environment('demo') || !is_string($connection) || $connection !== config('database.default')
            || !is_string($database) || $database === ''
            || $database !== config('database.connections.'.$connection.'.database')
            || config('database.connections.'.$connection.'.url')
            || config('database.connections.'.$connection.'.read') || config('database.connections.'.$connection.'.write')
            || !in_array(config('database.connections.'.$connection.'.driver'), ['sqlite', 'mysql', 'mariadb'], true)
            || !is_string($store) || !in_array(config('cache.stores.'.$store.'.driver'), ['file', 'redis'], true)
            || $limit === false || $limit < 1 || $limit > 1000) {
            $this->error('Expiration target or lock configuration is not explicitly approved; stopped.');
            return self::FAILURE;
        }
        if (DB::connection()->getDatabaseName() !== $database) {
            $this->error('Resolved expiration database differs from the approved target.');
            return self::FAILURE;
        }
        $lock = Cache::store($store)->lock('orders-expire:'.hash('sha256', $connection.'|'.$database), 600);
        if (!$lock->get()) {
            $this->info('Another expiration batch holds the lock. No orders processed.');
            return self::SUCCESS;
        }
        $original = app(TenantContext::class);
        $counts = ['expired' => 0, 'skipped' => 0, 'failed' => 0];
        try {
            // Explicit cross-tenant technical selection; each action below gets its
            // own tenant context and rechecks eligibility under its order row lock.
            $candidates = DB::table('orders')->where('status', 'PENDING')->where('payment_status', 'UNPAID')
                ->whereNull('pos_session_id')
                ->whereNotNull('reservation_expires_at')->where('reservation_expires_at', '<=', now())
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')->from('deliveries')->whereColumn('deliveries.order_id', 'orders.id')
                        ->whereColumn('deliveries.store_id', 'orders.store_id')->whereNotNull('picked_up_at');
                })->orderBy('id')->limit($limit)->get(['id', 'store_id']);
            foreach ($candidates as $candidate) {
                try {
                    $tenant = new TenantContext;
                    $tenant->setFromStore(Store::findOrFail($candidate->store_id));
                    app()->instance(TenantContext::class, $tenant);
                    $expire->execute($candidate->id);
                    $counts['expired']++;
                    Log::info('order_expiration.expired', ['order_id' => $candidate->id, 'store_id' => $candidate->store_id]);
                } catch (ValidationException) {
                    $counts['skipped']++;
                    Log::notice('order_expiration.skipped', ['order_id' => $candidate->id, 'store_id' => $candidate->store_id]);
                } catch (\Throwable $error) {
                    $counts['failed']++;
                    // No raw exception message, SQL bindings, contacts or secrets.
                    Log::error('order_expiration.failed', ['order_id' => $candidate->id,
                        'store_id' => $candidate->store_id, 'exception_class' => $error::class]);
                }
            }
            $this->info("Expired={$counts['expired']} skipped={$counts['skipped']} failed={$counts['failed']}");
            return $counts['failed'] ? self::FAILURE : self::SUCCESS;
        } finally {
            app()->instance(TenantContext::class, $original);
            $lock->release();
        }
    }
}
