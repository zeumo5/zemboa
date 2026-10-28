<?php

namespace App\Http\Controllers\Professional;

use App\Http\Controllers\Controller;
use App\Http\Controllers\MarketplaceController;
use App\Http\Requests\Professional\SaveStoreCustomizationRequest;
use App\Models\StoreSetting;
use App\Services\StoreCustomization;
use App\Support\StoreCustomizationAccess;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StoreCustomizationController extends Controller
{
    public function index(Request $request, StoreCustomization $customization): Response
    {
        $actor = $request->user('web');
        abort_unless(StoreCustomizationAccess::readable($actor), 403);
        $record = $customization->current();
        $store = app(TenantContext::class)->store();
        $settings = StoreSetting::first();
        $editable = StoreCustomizationAccess::owner($actor);
        $draft = $editable ? $customization->payload($record) : [];
        $section = $request->validate(['section' => ['nullable', 'in:identity,appearance,information,hours,commerce,documents,preview']])['section'] ?? 'identity';
        $values = array_replace($store->only(StoreCustomization::IDENTITY), $settings?->toArray() ?? [], [
            'reservation_duration_minutes' => $settings?->reservation_duration_minutes ?? 30,
            'allow_partial_payments' => $settings?->allow_partial_payments ?? false,
            'delivery_enabled' => $settings?->delivery_enabled ?? true, 'pickup_enabled' => $settings?->pickup_enabled ?? true,
        ]);
        foreach (['identity', 'appearance', 'information', 'hours', 'commerce'] as $area) {
            $values = array_replace($values, $draft[$area] ?? []);
        }

        return response()->view('professional.customization.index', compact('actor', 'record', 'store', 'settings', 'editable', 'draft', 'values', 'section'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function save(SaveStoreCustomizationRequest $request, StoreCustomization $customization): RedirectResponse
    {
        $customization->save($request->safe()->except(['logo', 'banner']), array_filter($request->safe()->only(['logo', 'banner']), fn ($file) => $file instanceof UploadedFile));

        return redirect()->route('professional.customization.index', ['section' => $request->input('section')])->with('success', __('customization.saved'));
    }

    public function publish(Request $request, StoreCustomization $customization): RedirectResponse
    {
        $customization->publish((int) $request->validate(['revision' => ['required', 'integer', 'min:0']])['revision']);

        return redirect()->route('professional.customization.index')->with('success', __('customization.published'));
    }

    public function discard(Request $request, StoreCustomization $customization): RedirectResponse
    {
        $customization->discard((int) $request->validate(['revision' => ['required', 'integer', 'min:0']])['revision']);

        return redirect()->route('professional.customization.index')->with('success', __('customization.discarded'));
    }

    public function preview(Request $request, StoreCustomization $customization): Response
    {
        abort_unless(StoreCustomizationAccess::owner($request->user('web')), 403);
        $page = $request->validate(['page' => ['nullable', 'in:home,about,services,catalog,categories']])['page'] ?? 'home';
        $draft = $customization->payload($customization->current());
        $store = clone app(TenantContext::class)->store();
        $store->fill(array_replace($draft['identity'] ?? [], $draft['information'] ?? []));
        app(TenantContext::class)->setFromStore($store);
        $settings = StoreSetting::first() ?? new StoreSetting;
        $settings->fill(array_replace($draft['appearance'] ?? [], $draft['hours'] ?? [], $draft['commerce'] ?? []));
        foreach ($draft['media'] ?? [] as $slot => $path) {
            $settings->setAttribute($slot.'_path', null);
            if ($path) {
                $settings->setAttribute('preview_'.$slot.'_url', route('professional.customization.media', $slot));
            }
        }
        $previousLocale = app()->getLocale();
        app()->setLocale(in_array($settings->default_locale, ['fr', 'en'], true) ? $settings->default_locale : $previousLocale);
        try {
            $view = app(MarketplaceController::class)->renderShop($request, $store->slug, $page, $settings)->with('isStorePreview', true);

            return response($view->render())->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
                ->header('X-Robots-Tag', 'noindex, nofollow')->header('Content-Security-Policy', "frame-ancestors 'self'");
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    public function media(Request $request, string $slot, StoreCustomization $customization): StreamedResponse
    {
        abort_unless(StoreCustomizationAccess::owner($request->user('web')), 403);
        $path = $customization->payload($customization->current())['media'][$slot] ?? null;
        abort_unless(is_string($path), 404);
        $customization->privatePath($path, (int) app(TenantContext::class)->storeId());
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer']);
    }
}
