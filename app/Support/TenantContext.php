<?php

namespace App\Support;

use App\Models\Store;
use App\Models\User;
use RuntimeException;

class TenantContext
{
    private ?Store $store = null;

    public function setFromUser(User $user): void
    {
        if ($user->store_id === null) {
            throw new RuntimeException(
                'Cet utilisateur n’appartient à aucune boutique.'
            );
        }

        $this->store = $user->store;
    }

    public function store(): Store
    {
        if ($this->store === null) {
            throw new RuntimeException(
                'Aucune boutique active dans le TenantContext.'
            );
        }

        return $this->store;
    }

    public function storeId(): int
    {
        return $this->store()->id;
    }
}