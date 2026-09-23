<?php

declare(strict_types=1);

namespace App\Queries;

use App\Support\Security\PiiHasher;
use Illuminate\Support\Facades\DB;

final class CustomerLookupQuery
{
    /**
     * 顧客検索。対象は氏名・カナ・電話番号・会員番号（§15）。
     * 会員番号は正式なDB項目 `customers.member_no`（例：ARK000164）。
     *
     * @return list<array{user_id: int, name: string, kana: string|null, member_no: string}>
     */
    public function search(string $search, int $limit = 20): array
    {
        $search = trim($search);

        if ($search === '') {
            return [];
        }

        $normalizedPhone = PiiHasher::normalizePhone($search);
        $isPhoneLike = is_string($normalizedPhone) && preg_match('/^\d{10,11}$/D', $normalizedPhone) === 1;
        $memberNoLike = $this->memberNoSearchValue($search);

        $query = DB::table('customers')
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->select(['customers.user_id', 'users.name', 'customers.kana', 'customers.member_no']);

        if ($isPhoneLike) {
            $query->where('customers.phone_hmac', PiiHasher::phoneHmac($search));
        } elseif ($memberNoLike !== null) {
            // 会員番号（ARK000164 / 000164 / 164 いずれの入力形式でも部分一致を許容）。
            $query->where(function ($query) use ($search, $memberNoLike): void {
                $query->where('users.name', 'like', "%{$search}%")
                    ->orWhere('customers.kana', 'like', "%{$search}%")
                    ->orWhere('customers.member_no', 'like', "%{$memberNoLike}%");
            });
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
                'member_no' => (string) $row->member_no,
            ])
            ->all();
    }

    /**
     * 1件だけを検索結果と同じ形で返す（仮登録直後に、そのまま予約フォームへ渡すため）。
     *
     * @return array{user_id: int, name: string, kana: string|null, member_no: string}
     */
    public function find(int $userId): array
    {
        $row = DB::table('customers')
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->where('customers.user_id', $userId)
            ->select(['customers.user_id', 'users.name', 'customers.kana', 'customers.member_no'])
            ->firstOrFail();

        return [
            'user_id' => (int) $row->user_id,
            'name' => (string) $row->name,
            'kana' => $row->kana === null || $row->kana === '' ? null : (string) $row->kana,
            'member_no' => (string) $row->member_no,
        ];
    }

    /**
     * 入力が会員番号らしい（"ARK000164" / "000164" / "164" のいずれか）場合、
     * `member_no` に対する LIKE 検索用の断片を返す。それ以外は null。
     */
    private function memberNoSearchValue(string $search): ?string
    {
        if (preg_match('/^ARK\d+$/i', $search) === 1) {
            return strtoupper($search);
        }

        if (preg_match('/^\d{1,6}$/', $search) === 1) {
            return ltrim($search, '0') === '' ? '0' : ltrim($search, '0');
        }

        return null;
    }
}
