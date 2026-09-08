<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can('customers.view') || $user->id === $customer->user_id;
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can('customers.manage') || $user->id === $customer->user_id;
    }
}
