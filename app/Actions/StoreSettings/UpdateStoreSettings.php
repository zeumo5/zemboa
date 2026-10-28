<?php

namespace App\Actions\StoreSettings;

use App\Models\Store;
use App\Models\StoreSetting;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class UpdateStoreSettings
{
    public function execute(array $data): StoreSetting
    {
        abort_unless(Schema::hasColumn('store_settings', 'commercial_configured'), 503, __('customization.setup'));

        return DB::transaction(function () use ($data) {
            $storeId = app(TenantContext::class)->storeId();

            // Verrouiller la boutique pour éviter
            // les créations concurrentes de paramètres.
            Store::query()
                ->whereKey($storeId)
                ->lockForUpdate()
                ->firstOrFail();

            // Le scope tenant limite la recherche
            // aux paramètres de la boutique courante.
            $settings = StoreSetting::query()->first();

            if ($settings === null) {
                Gate::authorize(
                    'create',
                    StoreSetting::class
                );

                $settings = new StoreSetting;
                $settings->fill(array_replace([
                    'reservation_duration_minutes' => 30,
                    'allow_partial_payments' => false,
                    'minimum_deposit_percent' => 0,
                    'delivery_enabled' => true,
                    'pickup_enabled' => true,
                    'returns_enabled' => false,
                ], $data));
                $settings->commercial_configured = null;
                $settings->save();
            } else {
                Gate::authorize('update', $settings);

                $settings->update($data);
            }

            return $settings->refresh();
        });
    }
}
