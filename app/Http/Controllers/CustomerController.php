<?php

namespace App\Http\Controllers;


use App\Actions\Customers\UpdateCustomer;
use App\Http\Requests\UpdateCustomerRequest;
use App\Actions\Customers\CreateCustomer;
use App\Http\Requests\StoreCustomerRequest;
use Illuminate\Http\RedirectResponse;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CustomerController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $customers = Customer::query()
            ->latest()
            ->get();

        return response()->json($customers);
    }

    public function show(Customer $customer): JsonResponse
    {
        Gate::authorize('view', $customer);

        return response()->json($customer);
    }

    public function store(
        StoreCustomerRequest $request,
        CreateCustomer $createCustomer
    ): RedirectResponse {
        Gate::authorize('create', Customer::class);

        $data = $request->validated();

        $customer = $createCustomer->execute(
            name: $data['name'],
            phone: $data['phone'],
            email: $data['email'] ?? null,
        );

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', 'Client créé avec succès.');
    }

    public function update(
        UpdateCustomerRequest $request,
        Customer $customer,
        UpdateCustomer $updateCustomer
    ): RedirectResponse {
        Gate::authorize('update', $customer);

        $data = $request->validated();

        $customer = $updateCustomer->execute(
            customer: $customer,
            name: $data['name'],
            phone: $data['phone'],
            email: $data['email'] ?? null,
            status: $data['status'],
        );

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', 'Client modifié avec succès.');
    }
}
