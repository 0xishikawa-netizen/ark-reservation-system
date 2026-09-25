<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\Product;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class CreateProduct
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, ?Authenticatable $actor): Product
    {
        return DB::transaction(function () use ($data, $actor): Product {
            $product = Product::query()->create($data);
            $this->auditLogger->log('product.created', $product, "商品「{$product->name}」を作成", $actor);

            return $product;
        });
    }
}
