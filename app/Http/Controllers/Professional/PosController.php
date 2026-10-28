<?php

namespace App\Http\Controllers\Professional;

use App\Actions\Pos\SubmitPosSale;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\ProductVariant;
use App\Models\StoreDocumentSetting;
use App\Models\StoreSetting;
use App\Support\BuyerIdentity;
use App\Support\PosAccess;
use App\Support\PosCart;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PosController extends Controller
{
    private function context(Request $request): array
    {
        $actor = PosAccess::authorize($request->user('web'));
        $store = $actor->store()->firstOrFail();
        $settings = StoreSetting::where('store_id', $store->id)->first(['store_id', 'logo_path']);

        return compact('actor', 'store', 'settings');
    }

    public function index(Request $request, PosCart $cart)
    {
        $context = $this->context($request);
        $actor = $context['actor'];
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'integer']]);
        $products = ProductVariant::where('store_id', $actor->store_id)->where('status', 'ACTIVE')
            ->whereHas('product', fn ($q) => $q->where('store_id', $actor->store_id)->where('status', 'ACTIVE'))
            ->with(['product.images', 'stockLevel', 'attributeValues.attribute']);
        if ($filters['q'] ?? null) {
            $search = $filters['q'];
            $products->where(fn ($q) => $q->where('sku', 'like', '%'.$search.'%')->orWhereHas('product', fn ($p) => $p->where('name', 'like', '%'.$search.'%')));
        }
        if ($filters['category'] ?? null) {
            $products->whereHas('product', fn ($q) => $q->where('category_id', $filters['category']));
        }
        $products = $products->orderBy('id')->paginate(24)->withQueryString();
        $categories = Category::where('store_id', $actor->store_id)->where('status', 'ACTIVE')->orderBy('name')->get(['id', 'name']);
        $registers = PosRegister::where('store_id', $actor->store_id)->where('status', 'ACTIVE')->orderBy('name')->get();
        $sessions = PosSession::where('store_id', $actor->store_id)->where('status', 'OPEN')->with('register');
        if ($actor->hasRole('CASHIER')) {
            $sessions->where('opened_by_user_id', $actor->id);
        }
        $sessions = $sessions->get();
        $closedSessions = PosSession::where('store_id', $actor->store_id)->where('status', 'CLOSED')->with('register');
        if ($actor->hasRole('CASHIER')) {
            $closedSessions->where('opened_by_user_id', $actor->id);
        }
        $closedSessions = $closedSessions->orderByDesc('closed_at')->limit(5)->get();
        $ready = PosAccess::ready();
        $documentSettings = $actor->hasPermission('store.document_settings.view') ? StoreDocumentSetting::where('store_id', $actor->store_id)->first() : null;
        $draft = $cart->draft($request, $actor);
        $request->session()->put($cart->key($actor), $draft);
        $cartError = null;
        try {
            $quote = $cart->quote($draft['items'], $actor);
        } catch (ValidationException $exception) {
            $quote = $cart->quote([], $actor);
            $cartError = __('pos_interface.unavailable');
        }

        return response()->view('professional.pos.index', $context + compact('products', 'categories', 'registers', 'sessions', 'closedSessions', 'ready', 'filters', 'draft', 'quote', 'cartError', 'documentSettings'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function cart(Request $request, PosCart $cart)
    {
        $actor = PosAccess::authorize($request->user('web'));
        $data = $request->validate(['operation' => ['required', Rule::in(['add', 'set', 'remove', 'clear'])],
            'variant_id' => ['required_unless:operation,clear', 'integer', 'min:1'], 'quantity' => ['required_if:operation,set', 'integer', 'min:1', 'max:999'],
            'price' => ['prohibited'], 'total' => ['prohibited'], 'store_id' => ['prohibited']]);
        $draft = $cart->draft($request, $actor);
        $id = $data['variant_id'] ?? null;
        if ($data['operation'] === 'clear') {
            $draft['items'] = [];
        } elseif ($data['operation'] === 'remove') {
            unset($draft['items'][$id]);
        } else {
            $draft['items'][$id] = $data['operation'] === 'add' ? ($draft['items'][$id] ?? 0) + 1 : (int) $data['quantity'];
        }
        $quote = $cart->quote($draft['items'], $actor);
        $draft['key'] = bin2hex(random_bytes(32));
        $request->session()->put($cart->key($actor), $draft);

        return response()->json(['html' => view('professional.pos.cart', compact('quote', 'draft'))->render()])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, PosCart $cart, SubmitPosSale $submit)
    {
        $actor = PosAccess::authorize($request->user('web'));
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'min:1'], 'key' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'quote_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'mode' => ['required', Rule::in(['UNPAID', 'CASH'])],
            'customer_name' => ['nullable', 'string', 'max:150'], 'customer_phone' => ['nullable', 'string', 'max:64'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'cash_received' => ['required_if:mode,CASH', 'nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D'],
            'price' => ['prohibited'], 'total' => ['prohibited'], 'discount' => ['prohibited'], 'store_id' => ['prohibited'], 'items' => ['prohibited'],
        ]);
        $data['customer_name'] = $data['customer_name'] ?? '';
        $data['customer_phone'] = $data['customer_phone'] ?? '';
        $data['customer_email'] = $data['customer_email'] ?? null;
        if ($data['customer_phone'] !== '') {
            $phone = BuyerIdentity::phoneOrNull($data['customer_phone']);
            if ($phone === null) {
                throw ValidationException::withMessages(['customer_phone' => __('team.invalid_phone')]);
            }
            $data['customer_phone'] = $phone;
        }
        $draft = $cart->draft($request, $actor);
        if (! hash_equals($draft['key'], $data['key'])) {
            $last = $request->session()->get($cart->key($actor).'-last');
            abort_unless(is_array($last) && hash_equals($last['key'], $data['key']), 409);
            $draft = $last;
        }
        $order = $submit->execute($actor, $data, $draft['items']);
        $request->session()->put($cart->key($actor).'-last', $draft);
        $request->session()->put($cart->key($actor), ['items' => [], 'key' => bin2hex(random_bytes(32))]);

        return response()->json(['redirect' => route('pos.interface.show', $order->id)], 201);
    }

    public function history(Request $request)
    {
        $context = $this->context($request);
        $actor = PosAccess::authorize($context['actor'], 'orders.view');
        $endDateRules = ['nullable', 'date_format:Y-m-d'];
        if ($request->filled('from')) {
            $endDateRules[] = 'after_or_equal:from';
        }
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'payment' => ['nullable', Rule::in(['UNPAID', 'PARTIALLY_PAID', 'PAID'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => $endDateRules]);
        $query = Order::where('store_id', $actor->store_id)->whereNotNull('pos_session_id')->with('posSession.openedBy');
        if ($actor->hasRole('CASHIER')) {
            $query->whereHas('posSession', fn ($q) => $q->where('opened_by_user_id', $actor->id));
        }
        if ($filters['q'] ?? null) {
            $query->where('order_number', 'like', '%'.$filters['q'].'%');
        }
        if ($filters['payment'] ?? null) {
            $query->where('payment_status', $filters['payment']);
        }
        if ($filters['from'] ?? null) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if ($filters['to'] ?? null) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        $sales = $query->orderByDesc('id')->paginate(20)->withQueryString();

        return response()->view('professional.pos.history', $context + compact('sales', 'filters'))->header('Cache-Control', 'private, no-store');
    }

    public function collect(Request $request, int $orderId, SubmitPosSale $submit)
    {
        $actor = PosAccess::authorize($request->user('web'), 'payments.confirm');
        $data = $request->validate(['cash_received' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D'], 'confirmed' => ['accepted']]);
        $submit->collect($actor, $orderId, $data['cash_received']);

        return redirect()->route('pos.interface.show', $orderId);
    }

    public function show(Request $request, int $orderId)
    {
        $context = $this->context($request);
        $order = PosAccess::sale($context['actor'], $orderId);
        $documents = StoreDocumentSetting::where('store_id', $context['actor']->store_id)->first();

        return response()->view('professional.pos.show', $context + compact('order', 'documents'))->header('Cache-Control', 'private, no-store');
    }

    public function document(Request $request, int $orderId)
    {
        $actor = PosAccess::authorize($request->user('web'), 'orders.view');
        PosAccess::authorize($actor, 'payments.view');
        $order = PosAccess::sale($actor, $orderId);
        $data = $request->validate(['format' => ['required', Rule::in(['A4', '58', '80'])], 'type' => ['nullable', Rule::in(['invoice', 'receipt'])], 'download' => ['nullable', Rule::in(['pdf'])]]);
        abort_if(isset($data['download']) && $data['format'] !== 'A4', 422);
        $snapshot = $order->pos_document_snapshot;
        abort_unless(is_array($snapshot), 409, __('pos_interface.legacy_document'));
        $current = StoreDocumentSetting::where('store_id', $actor->store_id)->first();
        $kind = $data['type'] ?? ($data['format'] === 'A4' ? 'invoice' : 'receipt');
        $invoice = $kind === 'invoice';
        abort_if(isset($data['download']) && ! $invoice, 422);
        abort_unless(($invoice ? ($snapshot['invoice_enabled'] ?? false) && ($current?->digital_invoices_enabled ?? true)
            : ($snapshot['receipt_enabled'] ?? false) && ($current?->digital_receipts_enabled ?? true)), 403);
        $previous = app()->getLocale();
        try {
            app()->setLocale(in_array($snapshot['locale'], ['fr', 'en'], true) ? $snapshot['locale'] : 'fr');
            $html = view('professional.pos.document', ['order' => $order, 'snapshot' => $snapshot, 'format' => $data['format'], 'kind' => $kind, 'download' => isset($data['download'])])->render();
        } finally {
            app()->setLocale($previous);
        }
        if (isset($data['download'])) {
            abort_unless(class_exists(Dompdf::class), 503, __('pos_interface.pdf_unavailable'));
            $options = new Options;
            $options->setIsRemoteEnabled(false);
            $options->setIsPhpEnabled(false);
            $options->setIsJavascriptEnabled(false);
            $options->setChroot(resource_path('views/professional/pos'));
            $pdf = new Dompdf($options);
            $pdf->loadHtml($html, 'UTF-8');
            $pdf->setPaper('A4');
            $pdf->render();

            return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="invoice-'.$order->id.'.pdf"', 'Cache-Control' => 'private, no-store']);
        }

        return response($html)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
