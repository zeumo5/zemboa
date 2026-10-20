<?php
namespace App\Models;


use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreDocumentSetting extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'invoice_print_format',
        'receipt_print_format',
        'digital_invoices_enabled',
        'digital_receipts_enabled',
        'show_logo',
        'show_business_details',
        'footer_message',
        'document_locale',
    ];

    protected function casts(): array
    {
        return [
            'digital_invoices_enabled' => 'boolean',
            'digital_receipts_enabled' => 'boolean',
            'show_logo' => 'boolean',
            'show_business_details' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
