<?php

namespace App\Services;

use App\Actions\StoreSettings\UpdateStoreSettings;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Support\StoreCustomizationAccess;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StoreCustomization
{
    public const IDENTITY = ['name', 'slogan', 'description', 'address', 'email', 'phone', 'whatsapp'];

    private const FIELDS = [
        'identity' => ['name', 'slogan', 'description'],
        'information' => ['address', 'email', 'phone', 'whatsapp'],
        'appearance' => ['primary_color'],
        'hours' => ['opening_hours', 'default_locale'],
        'commerce' => ['delivery_enabled', 'pickup_enabled', 'reservation_duration_minutes', 'allow_partial_payments', 'minimum_deposit_percent', 'returns_enabled', 'return_window_days', 'low_stock_threshold'],
    ];

    public function ready(): void
    {
        foreach (['store_id', 'draft', 'base_hash', 'revision', 'published_at', 'created_at', 'updated_at'] as $column) {
            abort_unless(Schema::hasColumn('store_customizations', $column), 503, __('customization.setup'));
        }
        abort_unless(Schema::hasIndex('store_customizations', ['store_id'], 'unique')
            && Schema::hasColumn('store_settings', 'commercial_configured'), 503, __('customization.setup'));
    }

    public function current(): ?object
    {
        $this->ready();

        return DB::table('store_customizations')->where('store_id', app(TenantContext::class)->storeId())->first();
    }

    public function payload(?object $record): array
    {
        return $record?->draft ? json_decode($record->draft, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public function save(array $data, array $files): void
    {
        $this->authorize();
        $this->ready();
        $created = [];
        $obsolete = [];
        try {
            DB::transaction(function () use ($data, $files, &$created, &$obsolete): void {
                $store = $this->lockedStore();
                $record = $this->current();
                $this->revision($record, (int) $data['revision']);
                $payload = $this->payload($record);
                $section = $data['section'];
                $values = array_intersect_key($data, array_flip(self::FIELDS[$section] ?? []));
                $payload[$section] = array_replace($payload[$section] ?? [], $values);
                foreach (['logo', 'banner'] as $slot) {
                    if (! isset($files[$slot]) && ! ($data['remove_'.$slot] ?? false)) {
                        continue;
                    }
                    $old = $payload['media'][$slot] ?? null;
                    if ($old) {
                        $obsolete[] = $old;
                    }
                    $payload['media'][$slot] = null;
                    if (isset($files[$slot])) {
                        /** @var UploadedFile $file */
                        $file = $files[$slot];
                        $path = $file->store('stores/'.$store->id.'/branding-drafts', 'local');
                        if (! $path) {
                            throw ValidationException::withMessages([$slot => __('customization.storage_failed')]);
                        }
                        $created[] = $path;
                        $payload['media'][$slot] = $path;
                    }
                }
                DB::table('store_customizations')->updateOrInsert(['store_id' => $store->id], [
                    'draft' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'base_hash' => $record?->base_hash ?? $this->fingerprint($store),
                    'revision' => ($record?->revision ?? 0) + 1,
                    'created_at' => $record?->created_at ?? now(), 'updated_at' => now(),
                ]);
            }, 1);
        } catch (Throwable $exception) {
            foreach ($created as $path) {
                $this->cleanup('local', $path);
            }
            throw $exception;
        }
        foreach ($obsolete as $path) {
            $this->cleanup('local', $path);
        }
    }

    public function publish(int $revision): void
    {
        $this->authorize();
        $this->ready();
        $created = [];
        $obsolete = [];
        $private = [];
        try {
            DB::transaction(function () use ($revision, &$created, &$obsolete, &$private): void {
                $store = $this->lockedStore();
                $record = $this->current();
                $this->revision($record, $revision);
                abort_unless($record?->draft, 409, __('customization.no_draft'));
                if (! hash_equals($record->base_hash, $this->fingerprint($store))) {
                    throw ValidationException::withMessages(['revision' => __('customization.stale_publication')]);
                }
                $payload = $this->payload($record);
                $store->fill(array_replace($payload['identity'] ?? [], $payload['information'] ?? []));
                $store->save();
                $values = array_replace($payload['appearance'] ?? [], $payload['hours'] ?? [], $payload['commerce'] ?? []);
                $media = [];
                $settings = StoreSetting::first();
                foreach ($payload['media'] ?? [] as $slot => $path) {
                    abort_unless(in_array($slot, ['logo', 'banner'], true), 409);
                    $obsolete[] = $settings?->{$slot.'_path'};
                    $media[$slot.'_path'] = null;
                    if ($path !== null) {
                        $this->privatePath($path, (int) $store->id);
                        abort_unless(Storage::disk('local')->exists($path), 409, __('customization.missing_media'));
                        $target = 'stores/'.$store->id.'/branding/'.Str::uuid().'.'.pathinfo($path, PATHINFO_EXTENSION);
                        $created[] = $target;
                        $stream = Storage::disk('local')->readStream($path);
                        try {
                            if (! is_resource($stream) || ! Storage::disk('public')->put($target, $stream)) {
                                throw ValidationException::withMessages(['media' => __('customization.storage_failed')]);
                            }
                        } finally {
                            if (is_resource($stream)) {
                                fclose($stream);
                            }
                        }
                        $private[] = $path;
                        $media[$slot.'_path'] = $target;
                    }
                }
                if ($values !== [] || $media !== []) {
                    app(UpdateStoreSettings::class)->execute(array_replace($values, $media));
                }
                DB::table('store_customizations')->where('store_id', $store->id)->update([
                    'draft' => null, 'base_hash' => null, 'revision' => $record->revision + 1,
                    'published_at' => now(), 'updated_at' => now(),
                ]);
            }, 1);
        } catch (Throwable $exception) {
            foreach ($created as $path) {
                $this->cleanup('public', $path);
            }
            throw $exception;
        }
        foreach ($private as $path) {
            $this->cleanup('local', $path);
        }
        foreach (array_filter($obsolete) as $path) {
            $prefix = 'stores/'.app(TenantContext::class)->storeId().'/branding/';
            if (str_starts_with($path, $prefix) && ! str_contains($path, '..')
                && ! DB::table('store_settings')->where('logo_path', $path)->orWhere('banner_path', $path)->exists()) {
                $this->cleanup('public', $path);
            }
        }
    }

    public function discard(int $revision): void
    {
        $this->authorize();
        $paths = DB::transaction(function () use ($revision): array {
            $store = $this->lockedStore();
            $record = $this->current();
            $this->revision($record, $revision);
            $paths = array_filter($this->payload($record)['media'] ?? []);
            DB::table('store_customizations')->where('store_id', $store->id)->update([
                'draft' => null, 'base_hash' => null, 'revision' => $revision + 1, 'updated_at' => now(),
            ]);

            return $paths;
        });
        foreach ($paths as $path) {
            $this->cleanup('local', $path);
        }
    }

    public function privatePath(string $path, int $storeId): void
    {
        abort_unless(preg_match('~^stores/'.$storeId.'/branding-drafts/[a-zA-Z0-9]+\.(?:png|jpg|jpeg|webp)$~D', $path), 409);
    }

    private function authorize(): void
    {
        abort_unless(StoreCustomizationAccess::owner(auth('web')->user()), 403);
    }

    private function lockedStore(): Store
    {
        $store = Store::whereKey(app(TenantContext::class)->storeId())->lockForUpdate()->firstOrFail();
        abort_unless($store->status === 'ACTIVE', 403);

        return $store;
    }

    private function revision(?object $record, int $revision): void
    {
        if ((int) ($record?->revision ?? 0) !== $revision) {
            throw ValidationException::withMessages(['revision' => __('customization.stale')]);
        }
    }

    private function fingerprint(Store $store): string
    {
        return hash('sha256', json_encode([$store->getRawOriginal(), StoreSetting::first()?->getRawOriginal()], JSON_THROW_ON_ERROR));
    }

    private function cleanup(string $disk, string $path): void
    {
        $prefix = 'stores/'.app(TenantContext::class)->storeId().'/'.($disk === 'local' ? 'branding-drafts/' : 'branding/');
        if (! str_starts_with($path, $prefix) || str_contains($path, '..')) {
            return;
        }
        try {
            if (! Storage::disk($disk)->delete($path)) {
                Log::warning('Store branding cleanup deferred.');
            }
        } catch (Throwable) {
            Log::warning('Store branding cleanup deferred.');
        }
    }
}
