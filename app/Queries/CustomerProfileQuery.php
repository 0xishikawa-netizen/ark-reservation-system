<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Customer;

class CustomerProfileQuery
{
    public function get(Customer $customer): Customer
    {
        return $customer->loadMissing('user:id,name,email,email_verified_at');
    }
}
