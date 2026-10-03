<?php

namespace App\Models\Concerns;

use App\Models\Store;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToStore
{
    protected static function bootBelongsToStore(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenantContext = app(TenantContext::class);

            $builder->where(
                $builder->getModel()->qualifyColumn('store_id'),
                $tenantContext->storeId()
            );
        });

        static::creating(function ($model) {
            $model->store_id = app(TenantContext::class)->storeId();
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}