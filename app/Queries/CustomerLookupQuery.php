<?php

declare(strict_types=1);

namespace App\Queries;

use App\Support\Security\PiiHasher;
use Illuminate\Support\Facades\DB;

final class CustomerLookupQuery
{
    /** @return list<array{user_id: int, name: string, kana: string|null}> */
    public function search(string $search, int $limit = 20): array
    {
        $search = trim($search);

        if ($search === '') {
            return [];
        }

        $normalizedPhone = PiiHasher::normalizePhone($search);
        $query = DB::table('customers')
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->select(['customers.user_id', 'users.name', 'customers.kana']);

        if (is_string($normalizedPhone)
            && preg_match('/^\d{10,11}$/D', $normalizedPhone) === 1) {
            $query->where('customers.phone_hmac', PiiHasher::phoneHmac($search));
        } else {
            $query->where(function ($query) use ($search): void {
                $query->where('users.name', 'like', "%{$search}%")
                    ->orWhere('customers.kana', 'like', "%{$search}%");
            });
        }

        return $query
            ->orderBy('users.name')
            ->limit($limit)
            ->get()
            ->map(static fn (object $row): array => [
                'user_id' => (int) $row->user_id,
                'name' => (string) $row->name,
                'kana' => $row->kana === null ? null : (string) $row->kana,
            ])
            ->all();
    }
}
