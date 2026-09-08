<?php

declare(strict_types=1);

namespace App\Actions\Ticket;

use App\Models\TicketProduct;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateTicketProduct
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, ?Authenticatable $actor = null): TicketProduct
    {
        return DB::transaction(function () use ($data, $actor): TicketProduct {
            $product = TicketProduct::query()->create(Arr::only($data, [
                'name',
                'total_count',
                'price',
                'validity_days',
                'is_active',
                'sort_order',
            ]));

            $this->auditLogger->log(
                'ticket_product.created',
                $product,
                "回数券商品「{$product->name}」を作成",
                $actor,
            );

            return $product;
        });
    }
}
