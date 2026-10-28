<?php

namespace App\Http\Requests\Professional;

use App\Http\Requests\UpdateStoreSettingRequest;
use App\Support\StoreCustomizationAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveStoreCustomizationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $hours = $this->input('opening_hours');
        if (is_array($hours)) {
            $this->merge(['opening_hours' => array_filter($hours, fn ($day) => ! is_array($day) || filled($day['open'] ?? null) || filled($day['close'] ?? null))]);
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return StoreCustomizationAccess::owner($this->user('web'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $settings = (new UpdateStoreSettingRequest)->rules();
        $fields = match ($this->input('section')) {
            'identity' => [
                'name' => ['sometimes', 'required', 'string', 'max:150'],
                'slogan' => ['sometimes', 'nullable', 'string', 'max:255'],
                'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'logo' => $this->imageRules(2048, 2000),
                'banner' => $this->imageRules(5120, 4096),
                'remove_logo' => ['sometimes', 'boolean'], 'remove_banner' => ['sometimes', 'boolean'],
            ],
            'appearance' => ['primary_color' => $settings['primary_color']],
            'information' => [
                'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'email' => ['sometimes', 'nullable', 'email', 'max:255'],
                'phone' => ['sometimes', 'nullable', 'string', 'max:25', 'regex:/^\+?[0-9 ()-]{6,25}$/'],
                'whatsapp' => ['sometimes', 'nullable', 'string', 'max:25', 'regex:/^\+?[0-9 ()-]{6,25}$/'],
            ],
            'hours' => array_intersect_key($settings, array_flip(['opening_hours', 'opening_hours.*', 'opening_hours.*.open', 'opening_hours.*.close', 'default_locale'])),
            'commerce' => array_intersect_key($settings, array_flip(['delivery_enabled', 'pickup_enabled', 'reservation_duration_minutes', 'allow_partial_payments', 'minimum_deposit_percent', 'returns_enabled', 'return_window_days', 'low_stock_threshold'])),
            default => [],
        };

        return array_replace($fields, [
            'section' => ['required', 'in:identity,appearance,information,hours,commerce'],
            'revision' => ['required', 'integer', 'min:0'],
            'store_id' => ['missing'], 'slug' => ['missing'], 'status' => ['missing'],
            'logo_path' => ['missing'], 'banner_path' => ['missing'],
            'wallet_number' => ['missing'], 'merchant_account_id' => ['missing'],
            'commercial_configured' => ['missing'], 'allow_reservations' => ['missing'],
        ]);
    }

    private function imageRules(int $size, int $dimension): array
    {
        return ['sometimes', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'extensions:jpg,jpeg,png,webp', 'max:'.$size, 'dimensions:min_width=16,min_height=16,max_width='.$dimension.',max_height='.$dimension];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['logo', 'banner'] as $slot) {
                if ($this->hasFile($slot) && $this->boolean('remove_'.$slot)) {
                    $validator->errors()->add($slot, __('customization.media_conflict'));
                }
            }
            $openingHours = $this->input('opening_hours', []);
            foreach (is_array($openingHours) ? $openingHours : [] as $day => $hours) {
                if (is_array($hours) && ! empty($hours['open']) && ! empty($hours['close']) && $hours['open'] >= $hours['close']) {
                    $validator->errors()->add('opening_hours.'.$day.'.close', __('customization.hours_invalid'));
                }
            }
        }];
    }
}
