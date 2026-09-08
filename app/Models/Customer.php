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

class Customer extends Model
{
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
