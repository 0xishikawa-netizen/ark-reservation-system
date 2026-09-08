<?php

declare(strict_types=1);

namespace App\Actions\Ticket;

use App\Models\TicketProduct;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class ToggleTicketProductActive
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        TicketProduct $product,
        bool $active,
        ?Authenticatable $actor = null,
    ): TicketProduct {
        return DB::transaction(function () use ($product, $active, $actor): TicketProduct {
            $product->update(['is_active' => $active]);

            $this->auditLogger->log(
                $active ? 'ticket_product.activated' : 'ticket_product.deactivated',
                $product,
                sprintf('回数券商品「%s」を%s', $product->name, $active ? '有効化' : '無効化'),
                $actor,
            );

            return $product->refresh();
        });
    }
}
