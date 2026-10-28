<?php

namespace App\Actions\Pos;

use App\Actions\Payments\ConfirmPayment;
use App\Actions\Payments\FinalizeOrderPayment;
use App\Models\Order;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\StoreDocumentSetting;
use App\Models\User;
use App\Support\PosAccess;
use App\Support\PosCart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class SubmitPosSale
{
    public function collect(User $actor, int $orderId, string $cashReceived): Order
    {
        PosAccess::authorize($actor, 'payments.confirm');
        $sale = PosAccess::sale($actor, $orderId);

        return DB::transaction(function () use ($actor, $sale, $cashReceived): Order {
            $session = PosSession::where('store_id', $actor->store_id)->findOrFail($sale->pos_session_id);
            PosRegister::where('store_id', $actor->store_id)->whereKey($session->pos_register_id)->lockForUpdate()->firstOrFail();
            PosSession::where('store_id', $actor->store_id)->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $order = Order::where('store_id', $actor->store_id)->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($order->payment_status === 'PAID') {
                $payment = $order->payments()->where('status', 'CONFIRMED')->where('method', 'CASH')->orderByDesc('id')->first();
                $money = app(FinalizeOrderPayment::class);
                abort_unless($payment && $money->amountToCents($payment->cash_received) === $money->amountToCents($cashReceived), 409);

                return $order;
            }
            $money = app(FinalizeOrderPayment::class);
            $paid = $order->payments()->where('status', 'CONFIRMED')->get()->sum(fn ($p) => $money->amountToCents($p->amount));
            $due = $money->amountToCents($order->total) - $paid;
            abort_unless($due > 0, 422);
            $amount = intdiv($due, 100).'.'.str_pad((string) ($due % 100), 2, '0', STR_PAD_LEFT);
            $payment = app(CreatePosCashPayment::class)->execute($session->id, $order->id, $amount, $cashReceived, $actor);
            app(ConfirmPayment::class)->execute($payment->id, $actor->id);

            return $order->refresh();
        }, 1);
    }

    public function execute(User $actor, array $data, array $items): Order
    {
        PosAccess::authorize($actor);
        PosAccess::schemaReady();
        ksort($items, SORT_NUMERIC);
        abort_unless(in_array($data['mode'], ['UNPAID', 'CASH'], true), 422);
        if ($data['mode'] === 'CASH') {
            PosAccess::authorize($actor, 'payments.confirm');
        }
        $requestHash = hash('sha256', json_encode([$actor->id, $items, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $data, $items, $requestHash): Order {
            $lookup = PosSession::where('store_id', $actor->store_id)->findOrFail($data['session_id']);
            PosRegister::where('store_id', $actor->store_id)->whereKey($lookup->pos_register_id)->lockForUpdate()->firstOrFail();
            $session = PosSession::where('store_id', $actor->store_id)->whereKey($lookup->id)->lockForUpdate()->firstOrFail();
            abort_unless(! $actor->hasRole('CASHIER') || (int) $session->opened_by_user_id === (int) $actor->id, 403);
            $existing = Order::where('store_id', $actor->store_id)->where('pos_key_hash', hash('sha256', $data['key']))->first();
            if ($existing) {
                abort_unless((int) $existing->pos_created_by_user_id === (int) $actor->id && hash_equals($existing->pos_request_hash, $requestHash), 409);

                return $existing;
            }
            $quote = app(PosCart::class)->quote($items, $actor);
            if (! hash_equals($quote['quote_hash'], $data['quote_hash'])) {
                throw ValidationException::withMessages(['items' => __('pos_interface.quote_changed')]);
            }
            $order = app(CreatePosOrder::class)->execute(
                sessionId: $session->id, customerName: $data['customer_name'], customerPhone: $data['customer_phone'],
                customerEmail: $data['customer_email'] ?? null,
                items: array_map(fn ($id, $quantity) => ['product_variant_id' => $id, 'quantity' => $quantity], array_keys($items), array_values($items)), actor: $actor
            );
            if ($order->total !== $quote['amounts']['total']) {
                throw ValidationException::withMessages(['items' => __('pos_interface.quote_changed')]);
            }
            foreach ($order->items as $item) {
                $line = collect($quote['lines'])->firstWhere('product_variant_id', $item->product_variant_id);
                $item->variant_description = $line['variant'] ?: null;
                $item->save();
            }
            $store = $actor->store()->firstOrFail();
            $documentSettings = StoreDocumentSetting::where('store_id', $store->id)->first();
            $order->forceFill([
                'pos_key_hash' => hash('sha256', $data['key']), 'pos_request_hash' => $requestHash, 'pos_created_by_user_id' => $actor->id,
                'pos_document_snapshot' => [
                    'seller' => $store->only(['name', 'email', 'phone', 'address']), 'cashier' => $actor->name,
                    'locale' => $documentSettings?->document_locale ?? app()->getLocale(),
                    'show_business_details' => $documentSettings?->show_business_details ?? true,
                    'logo_data' => ($documentSettings?->show_logo ?? true) ? $this->logo($store->settings?->logo_path) : null,
                    'footer' => $documentSettings?->footer_message,
                    'invoice_enabled' => $documentSettings?->digital_invoices_enabled ?? true,
                    'receipt_enabled' => $documentSettings?->digital_receipts_enabled ?? true,
                ],
            ])->save();
            if ($data['mode'] === 'CASH') {
                $payment = app(CreatePosCashPayment::class)->execute($session->id, $order->id, $order->total, $data['cash_received'], $actor);
                app(ConfirmPayment::class)->execute($payment->id, $actor->id);
            }

            return $order->refresh();
        }, 1);
    }

    private function logo(?string $path): ?string
    {
        if (! $path || config('filesystems.disks.public.driver') !== 'local') {
            return null;
        }
        $root = realpath(config('filesystems.disks.public.root'));
        $file = realpath(Storage::disk('public')->path($path));
        if (! $root || ! $file || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file) || filesize($file) > 524288) {
            return null;
        }
        $info = @getimagesize($file);
        if (! $info || ! in_array($info['mime'], ['image/png', 'image/jpeg'], true) || $info[0] > 2000 || $info[1] > 2000) {
            return null;
        }

        return 'data:'.$info['mime'].';base64,'.base64_encode(file_get_contents($file));
    }
}
