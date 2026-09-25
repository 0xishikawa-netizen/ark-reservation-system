<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** 会計で選択する支払方法マスタ。既存Stripe paymentsとは別責務。 */
class PaymentMethod extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'is_enabled', 'display_order', 'external_provider'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'display_order' => 'integer'];
    }
}
