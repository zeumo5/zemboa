<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $distribution = Store::query()->select('status')->selectRaw('COUNT(*) as count')->groupBy('status')->orderBy('status')->get();
        $counts = $distribution->pluck('count', 'status');
        $professionals = User::whereHas('roles', fn ($query) => $query->whereIn('code', ['STORE_OWNER', 'STORE_MANAGER', 'CASHIER', 'DELIVERY_AGENT']))->count();
        $stores = Store::query()->latest('id')->limit(5)->get(['id', 'name', 'slug', 'status', 'created_at']);

        return response()->view('admin.dashboard', compact('counts', 'professionals', 'stores', 'distribution'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
