<?php
namespace App\Actions\StoreSettings;


use App\Models\Store;
use App\Models\StoreSetting;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateStoreSettings
{
    public function execute(array $data): StoreSetting
    {
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

                $settings = StoreSetting::create($data);
            } else {
                Gate::authorize('update', $settings);

                $settings->update($data);
            }

            return $settings->refresh();
        });
    }
}
