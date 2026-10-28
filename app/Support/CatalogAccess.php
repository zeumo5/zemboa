<?php

namespace App\Support;

use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogAccess
{
    /** @return array{actor: User, store: Store, settings: ?StoreSetting} */
    public static function context(Request $request, string $permission, bool $write = false): array
    {
        $actor = $request->user('web');
        abort_unless(AccountDestination::professionalIdentity($actor)
            && $actor->roles()->whereIn('code', $write ? ['STORE_OWNER', 'STORE_MANAGER'] : ['STORE_OWNER', 'STORE_MANAGER', 'CASHIER'])->exists()
            && $actor->hasPermission($permission), 403);
        $store = app(TenantContext::class)->store();
        abort_unless((int) $actor->store_id === (int) $store->id, 403);

        return ['actor' => $actor, 'store' => $store, 'settings' => StoreSetting::query()->first()];
    }

    public static function version(Model $model): string
    {
        $columns = array_merge($model->getFillable(), [$model->getKeyName(), 'store_id', $model->getCreatedAtColumn(), $model->getUpdatedAtColumn()]);
        $attributes = array_intersect_key($model->getRawOriginal(), array_flip($columns));
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    public static function mutate(Model $model, Request $request, callable $operation): mixed
    {
        $data = $request->validate(['version' => ['required', 'string', 'size:64']]);

        return self::persist(fn () => DB::transaction(function () use ($model, $data, $operation) {
            $current = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
            if (! hash_equals(self::version($current), $data['version'])) {
                throw ValidationException::withMessages(['version' => __('catalog.stale')]);
            }

            return $operation($current);
        }));
    }

    public static function persist(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['catalog' => __('catalog.duplicate')]);
        } catch (ValidationException $exception) {
            if (app()->getLocale() !== 'en') {
                throw $exception;
            }
            $errors = $exception->errors();
            foreach (['category' => 'contains_products', 'category_id' => 'category_foreign', 'is_default' => 'default_required'] as $field => $message) {
                if (isset($errors[$field])) {
                    $errors[$field] = [__('catalog.'.$message)];
                }
            }
            throw ValidationException::withMessages($errors);
        }
    }
}
