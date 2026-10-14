<?php
namespace App\Http\Requests;


use Illuminate\Foundation\Http\FormRequest;

class UpdateStoreSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(
            'settings.update'
        ) ?? false;
    }

    public function rules(): array
    {
        return [
            // Apparence
            'primary_color' => [
                'sometimes',
                'required',
                'regex:/^#[0-9a-fA-F]{6}$/',
            ],

            // Réservations
            'allow_reservations' => [
                'sometimes',
                'boolean',
            ],
            'reservation_duration_minutes' => [
                'sometimes',
                'integer',
                'min:1',
                'max:43200',
            ],

            // Paiements et acomptes
            'allow_partial_payments' => [
                'sometimes',
                'boolean',
            ],
            'minimum_deposit_percent' => [
                'sometimes',
                'integer',
                'between:0,100',
            ],

            // Livraison
            'delivery_enabled' => [
                'sometimes',
                'boolean',
            ],
            'pickup_enabled' => [
                'sometimes',
                'boolean',
            ],

            // Retours
            'returns_enabled' => [
                'sometimes',
                'boolean',
            ],
            'return_window_days' => [
                'sometimes',
                'integer',
                'min:0',
                'max:365',
            ],

            // Stock
            'low_stock_threshold' => [
                'sometimes',
                'integer',
                'min:0',
            ],

            // Horaires
            'opening_hours' => [
                'sometimes',
                'nullable',
                'array:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            ],
            'opening_hours.*' => [
                'array:open,close',
            ],
            'opening_hours.*.open' => [
                'required_with:opening_hours.*.close',
                'date_format:H:i',
            ],
            'opening_hours.*.close' => [
                'required_with:opening_hours.*.open',
                'date_format:H:i',
            ],

            // Notifications
            'notify_new_orders' => [
                'sometimes',
                'boolean',
            ],
            'notify_low_stock' => [
                'sometimes',
                'boolean',
            ],
        ];
    }
}
