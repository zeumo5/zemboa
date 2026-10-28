<?php

namespace App\Http\Controllers\Professional;

use App\Actions\Refunds\ApproveRefund;
use App\Actions\Refunds\PrepareRefund;
use App\Actions\Refunds\SimulateRefund;
use App\Actions\Returns\CreateReturnRequest;
use App\Actions\Returns\InspectReturnRequest;
use App\Actions\Returns\ReceiveReturnRequest;
use App\Actions\Returns\RestockReturnRequest;
use App\Actions\Returns\ReviewReturnRequest;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Support\SalesAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AfterSalesController extends Controller
{
    public function index(Request $request): View
    {
        $context = SalesAccess::context($request);
        $filters = SalesAccess::filters($request, ['status' => ['nullable', 'in:REQUESTED,APPROVED,REJECTED,RECEIVED,INSPECTED']]);
        $query = ReturnRequest::with('order:id,store_id,order_number,customer_name')->withCount('items');
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['q'] ?? null)) {
            $term = '%'.$filters['q'].'%';
            $query->where(fn ($q) => $q->where('reason', 'like', $term)->orWhereHas('order', fn ($o) => $o->where(fn ($o) => $o->where('order_number', 'like', $term)->orWhere('customer_name', 'like', $term))));
        }
        SalesAccess::period($query, $filters);
        $cases = $query->latest('id')->paginate(20)->withQueryString();
        $counts = ReturnRequest::select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('professional.sales.returns', $context + compact('cases', 'counts', 'filters'));
    }

    public function show(Request $request, ReturnRequest $case): View
    {
        $context = SalesAccess::context($request);
        $staff = fn ($q) => $q->where('store_id', $context['store']->id)->select('id', 'name');
        $case->load(['order', 'items.orderItem' => fn ($q) => $q->where('order_id', $case->order_id), 'reviewer' => $staff, 'receiver' => $staff, 'inspector' => $staff]);
        abort_unless($case->order, 404);
        $refund = $context['actor']->hasPermission('refunds.view') ? Refund::where('return_request_id', $case->id)->where('order_id', $case->order_id)->first() : null;

        return view('professional.sales.return', $context + compact('case', 'refund'));
    }

    public function create(Request $request, Order $order): View
    {
        $context = SalesAccess::context($request);
        abort_unless(SalesAccess::allowed($context['actor'], 'returns.manage', true), 403);
        $order->load('items');
        $version = $this->version($order);

        return view('professional.sales.return-create', $context + compact('order', 'version'));
    }

    private function version(Order $order): string
    {
        return hash('sha256', $order->store_id.'|'.$order->id.'|'.ReturnRequest::where('order_id', $order->id)->max('id'));
    }

    public function store(Request $request, Order $order, CreateReturnRequest $action): RedirectResponse
    {
        $context = SalesAccess::context($request);
        abort_unless(SalesAccess::allowed($context['actor'], 'returns.manage', true), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000'], 'items' => ['required', 'array', 'max:1000'], 'items.*' => ['required', 'integer', 'min:0'], 'version' => ['required', 'string', 'size:64']]);
        $items = [];
        foreach ($data['items'] as $id => $quantity) {
            abort_unless(filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0, 422);
            if ((int) $quantity > 0) {
                $items[] = ['order_item_id' => (int) $id, 'quantity' => (int) $quantity];
            }
        }
        $case = SalesAccess::workflow(fn () => DB::transaction(function () use ($order, $data, $items, $action): ReturnRequest {
            $current = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($this->version($current), $data['version']), 409, __('sales.stale'));

            return $action->execute($current->id, $items, $data['reason']);
        }));

        return redirect()->route('professional.returns.show', $case)->with('success', __('sales.saved'));
    }

    public function update(Request $request, ReturnRequest $case, string $operation): RedirectResponse
    {
        $context = SalesAccess::context($request);
        abort_unless(SalesAccess::allowed($context['actor'], 'returns.manage', true), 403);
        SalesAccess::workflow(function () use ($request, $case, $operation): void {
            match ($operation) {
                'review' => $this->review($request, $case),
                'receive' => app(ReceiveReturnRequest::class)->execute($case->id),
                'inspect' => app(InspectReturnRequest::class)->execute($case->id, $request->validate(['items' => ['required', 'array', 'max:1000']])['items']),
                'restock' => $this->restock($request, $case),
                default => abort(404),
            };
        });

        return redirect()->route('professional.returns.show', $case)->with('success', __('sales.saved'));
    }

    private function review(Request $request, ReturnRequest $case): ReturnRequest
    {
        $data = $request->validate(['decision' => ['required', 'in:APPROVED,REJECTED'], 'review_notes' => ['required_if:decision,REJECTED', 'nullable', 'string', 'max:1000']]);

        return app(ReviewReturnRequest::class)->execute($case->id, $data['decision'], $data['review_notes'] ?? null);
    }

    private function restock(Request $request, ReturnRequest $case): ReturnRequest
    {
        $request->validate(['confirmed_physical' => ['required', 'accepted']]);

        return app(RestockReturnRequest::class)->execute($case->id);
    }

    public function refund(Request $request, ReturnRequest $case, string $operation): RedirectResponse
    {
        $context = SalesAccess::context($request);
        abort_unless(SalesAccess::allowed($context['actor'], 'refunds.manage', true) && $context['actor']->hasPermission('refunds.view'), 403);
        $request->validate(['confirmed_simulation' => ['required', 'accepted']]);
        SalesAccess::workflow(function () use ($case, $operation): void {
            if ($operation === 'prepare') {
                app(PrepareRefund::class)->execute($case->id);

                return;
            }
            $refund = Refund::where('return_request_id', $case->id)->where('order_id', $case->order_id)->firstOrFail();
            match ($operation) {
                'approve' => app(ApproveRefund::class)->execute($refund->id),
                'simulate' => app(SimulateRefund::class)->execute($refund->id),
                default => abort(404),
            };
        });

        return redirect()->route('professional.returns.show', $case)->with('success', __('sales.saved'));
    }
}
