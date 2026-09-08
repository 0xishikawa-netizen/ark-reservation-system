<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Security\PiiHasher;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Laravel\Cashier\Billable;

class Customer extends Model
{
    /**
     * Cashier（Stripe 課金契約の記録専用）。業務状態・残回数・予約可否は memberships が SoR。
     */
    use Billable;

    use HasFactory;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'kana',
        'phone',
        'birthday',
        'gender',
        'note',
        'stripe_customer_id',
        'created_via',
    ];

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            if (! $customer->isDirty('phone')) {
                return;
            }

            $customer->phone_hmac = PiiHasher::phoneHmac($customer->phone);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Cashier は billable の `stripe_id` 属性を読み書きする。ARK は Phase 1 から
     * `customers.stripe_customer_id` を SoR にしているため、rename せずここでエイリアスする。
     * （旧式アクセサ/ミューテタ。`stripeId()` メソッドと衝突しない命名を使う。）
     */
    public function getStripeIdAttribute(): ?string
    {
        $value = $this->attributes['stripe_customer_id'] ?? null;

        return $value === null ? null : (string) $value;
    }

    public function setStripeIdAttribute(?string $value): void
    {
        $this->attributes['stripe_customer_id'] = $value;
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'customer_id', 'user_id');
    }

    /**
     * @return Attribute<?Carbon, never>
     */
    protected function birthday(): Attribute
    {
        // Laravel 13 は encrypted:date を組み込み cast として扱わないため、復号後に日付へ変換する。
        return Attribute::get(
            fn (?string $value): ?Carbon => $value === null
                ? null
                : Carbon::parse(Crypt::decryptString($value)),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone' => 'encrypted',
            'birthday' => 'encrypted',
        ];
    }
}
