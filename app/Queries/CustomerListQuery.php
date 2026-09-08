<?php

declare(strict_types=1);

namespace App\Queries;

use App\Support\Security\PiiHasher;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CustomerListQuery
{
    /** @return LengthAwarePaginator<int, object> */
    public function paginate(?string $search, int $perPage = 20): LengthAwarePaginator
    {
        $search = trim((string) $search);
        $normalizedPhone = PiiHasher::normalizePhone($search);

        return DB::table('customers')
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->select([
                'customers.user_id',
                'users.name',
                'users.email',
                'users.email_verified_at',
                'customers.kana',
                'customers.created_via',
                'customers.created_at',
            ])
            ->when($search !== '', function ($query) use ($search, $normalizedPhone): void {
                if (is_string($normalizedPhone)
                    && preg_match('/^\d{10,11}$/D', $normalizedPhone) === 1) {
                    $query->where('customers.phone_hmac', PiiHasher::phoneHmac($search));

                    return;
                }

                $query->where(function ($query) use ($search): void {
                    $query->where('users.name', 'like', "%{$search}%")
                        ->orWhere('customers.kana', 'like', "%{$search}%")
                        ->orWhere('users.email', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('customers.created_at')
            ->paginate($perPage);
    }
}
