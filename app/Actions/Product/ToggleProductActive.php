<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\Product;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class ToggleProductActive
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(Product $product, bool $active, ?Authenticatable $actor): Product
    {
        return DB::transaction(function () use ($product, $active, $actor): Product {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $locked->update(['is_active' => $active]);
            $this->auditLogger->log(
                $active ? 'product.activated' : 'product.deactivated',
                $locked,
                sprintf('商品「%s」を%s', $locked->name, $active ? '有効化' : '無効化'),
                $actor,
            );

            return $locked->refresh();
        });
    }
}
