<?php

namespace App\Http\Controllers;

use App\Models\StoreDocumentSetting;
use App\Models\User;
use App\Support\AccountDestination;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StoreDocumentSettingController extends Controller
{
    private const DEFAULTS = [
        'invoice_print_format' => 'A4',
        'receipt_print_format' => 'TICKET_80',
        'digital_invoices_enabled' => true,
        'digital_receipts_enabled' => true,
        'show_logo' => true,
        'show_business_details' => true,
        'footer_message' => null,
        'document_locale' => null,
    ];

    public function show(Request $request): JsonResponse
    {
        $this->authorizeAccess($request, 'store.document_settings.view');

        // Consultation sans créer d'enregistrement.
        $settings = StoreDocumentSetting::query()->first();

        return response()->json([
            'settings' => $this->formatSettings($settings),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeAccess($request, 'store.document_settings.update');
        $data = $request->validate([
            'invoice_print_format' => [
                'sometimes',
                'required',
                Rule::in(['A4', 'TICKET_58', 'TICKET_80']),
            ],
            'receipt_print_format' => [
                'sometimes',
                'required',
                Rule::in(['A4', 'TICKET_58', 'TICKET_80']),
            ],
            'digital_invoices_enabled' => [
                'sometimes',
                'required',
                'boolean',
            ],
            'digital_receipts_enabled' => [
                'sometimes',
                'required',
                'boolean',
            ],
            'show_logo' => [
                'sometimes',
                'required',
                'boolean',
            ],
            'show_business_details' => [
                'sometimes',
                'required',
                'boolean',
            ],
            'footer_message' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],
            'document_locale' => [
                'sometimes',
                'nullable',
                Rule::in(['fr', 'en']),
            ],
        ]);

        // Le trait BelongsToStore attribue store_id.
        // Le client ne peut pas choisir une autre boutique.
        $settings = StoreDocumentSetting::query()
            ->firstOrNew([]);

        $settings->fill($data);
        $settings->save();
        $settings->refresh();

        return response()->json([
            'message' => __('documents.settings_saved'),
            'settings' => $this->formatSettings($settings),
        ]);
    }

    private function authorizeAccess(
        Request $request,
        string $permission
    ): void {
        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401, 'Authentification requise.');
        }

        $storeId = app(TenantContext::class)->storeId();

        $allowedRole = $actor->roles()
            ->whereIn('code', [
                'STORE_OWNER',
                'STORE_MANAGER',
            ])
            ->exists();

        $forbiddenRole = $actor->roles()
            ->whereIn('code', [
                'DELIVERY_AGENT',
                'SUPER_ADMIN',
            ])
            ->exists();

        if (
            ! AccountDestination::professionalIdentity($actor) ||
            $storeId === null ||
            (int) $actor->store_id !== (int) $storeId ||
            $actor->status !== 'ACTIVE' ||
            ! $allowedRole ||
            $forbiddenRole ||
            ! $actor->hasPermission($permission)
        ) {
            throw new AuthorizationException(
                __('documents.settings_access_denied')
            );
        }

        if ($actor->store()->value('status') !== 'ACTIVE') {
            throw new AuthorizationException(
                __('documents.store_suspended')
            );
        }
    }

    private function formatSettings(
        ?StoreDocumentSetting $settings
    ): array {
        if ($settings === null) {
            return self::DEFAULTS;
        }

        return array_replace(
            self::DEFAULTS,
            $settings->only(array_keys(self::DEFAULTS))
        );
    }
}
