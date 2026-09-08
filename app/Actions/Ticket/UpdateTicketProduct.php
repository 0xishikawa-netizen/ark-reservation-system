<?php

declare(strict_types=1);

namespace App\Actions\Ticket;

use App\Models\TicketProduct;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateTicketProduct
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function execute(
        TicketProduct $product,
        array $data,
        ?Authenticatable $actor = null,
    ): TicketProduct {
        return DB::transaction(function () use ($product, $data, $actor): TicketProduct {
            $product->update(Arr::only($data, [
                'name',
                'total_count',
                'price',
                'validity_days',
                'is_active',
                'sort_order',
            ]));

            $this->auditLogger->log(
                'ticket_product.updated',
                $product,
                "回数券商品「{$product->name}」を更新",
                $actor,
            );

            return $product->refresh();
        });
    }
}
