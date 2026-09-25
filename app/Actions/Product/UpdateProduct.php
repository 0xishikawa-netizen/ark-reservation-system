<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\Product;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class UpdateProduct
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function execute(Product $product, array $data, ?Authenticatable $actor): Product
    {
        return DB::transaction(function () use ($product, $data, $actor): Product {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $locked->update($data);
            $this->auditLogger->log('product.updated', $locked, "商品「{$locked->name}」を更新", $actor);

            return $locked->refresh();
        });
    }
}
