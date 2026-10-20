<?php

namespace App\Services\Logistics;

use App\Models\Rcpa\VwOPENORDERSView;
use App\Services\Sync\TenciaSource;
use Illuminate\Support\Collection;

/**
 * Presentation fields for logistics screens from dbo.vwOPEN_ORDERS.
 *
 * @phpstan-type OpenOrderLine array{
 *     entity: string,
 *     line_unique_id: int,
 *     reference_nbr: string,
 *     customer_code: string,
 *     customer_name: string,
 *     contact_name: ?string,
 *     project_name: ?string,
 *     suburb: ?string,
 *     state: ?string,
 *     address_1: ?string,
 *     backorder_qty: float,
 *     line_delivery_date: ?string,
 *     stock_code: string,
 * }
 */
final class OpenOrderLineReader
{
    public function __construct(private TenciaSource $tencia) {}

    /**
     * @return Collection<int, OpenOrderLine>
     */
    public function lines(?string $entityCode = null): Collection
    {
        $query = $this->tencia->view('vwOPEN_ORDERS')
            ->where('BACKORDER_QTY', '>', 0);

        VwOPENORDERSView::excludeCancelled($query);

        if ($entityCode !== null) {
            $query->where('ENTITY', $entityCode);
        }

        return $query->get([
            'ENTITY',
            'REFERENCE_NBR',
            'CUSTOMER_CODE',
            'CUSTOMER_NAME',
            'CONTACT_NAME',
            'PROJECT_NAME',
            'ADDRESS_1',
            'SUBURB',
            'STATE',
            'STOCK_CODE',
            'BACKORDER_QTY',
            'LINE_DELIVERY_DATE',
            'LINE_UNIQUE_ID',
        ])->map(fn (object $row): array => [
            'entity' => (string) $row->ENTITY,
            'line_unique_id' => (int) $row->LINE_UNIQUE_ID,
            'reference_nbr' => (string) $row->REFERENCE_NBR,
            'customer_code' => (string) ($row->CUSTOMER_CODE ?? ''),
            'customer_name' => (string) ($row->CUSTOMER_NAME ?? ''),
            'contact_name' => filled($row->CONTACT_NAME ?? null) ? (string) $row->CONTACT_NAME : null,
            'project_name' => filled($row->PROJECT_NAME ?? null) ? (string) $row->PROJECT_NAME : null,
            'suburb' => filled($row->SUBURB ?? null) ? (string) $row->SUBURB : null,
            'state' => filled($row->STATE ?? null) ? (string) $row->STATE : null,
            'address_1' => filled($row->ADDRESS_1 ?? null) ? (string) $row->ADDRESS_1 : null,
            'backorder_qty' => (float) $row->BACKORDER_QTY,
            'line_delivery_date' => $row->LINE_DELIVERY_DATE !== null
                ? (string) $row->LINE_DELIVERY_DATE
                : null,
            'stock_code' => (string) $row->STOCK_CODE,
        ])->values();
    }

    /**
     * @return OpenOrderLine|null
     */
    public function findByLineId(string $entityCode, int|string $lineUniqueId): ?array
    {
        return $this->lines($entityCode)
            ->first(fn (array $line): bool => (string) $line['line_unique_id'] === (string) $lineUniqueId);
    }
}
