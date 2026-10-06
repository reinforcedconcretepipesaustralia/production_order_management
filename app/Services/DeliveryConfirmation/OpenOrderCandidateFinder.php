<?php

namespace App\Services\DeliveryConfirmation;

use App\Enums\DeliveryConfirmationStage;
use App\Models\DeliveryConfirmationSend;
use App\Models\Rcpa\VwOPENORDERSView;
use App\Services\Sync\TenciaSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Groups outstanding open-order lines into one candidate per sales order and stage.
 */
class OpenOrderCandidateFinder
{
    public function __construct(private TenciaSource $tencia) {}

    /**
     * @return Collection<int, OpenOrderGroup>
     */
    public function candidates(?CarbonImmutable $today = null): Collection
    {
        $today = ($today ?? CarbonImmutable::today('Australia/Melbourne'))->startOfDay();
        $overdueMaxDaysPast = max(1, (int) config('delivery-reminders.overdue_max_days_past', 14));

        $query = $this->tencia->view('vwOPEN_ORDERS')
            ->where('BACKORDER_QTY', '>', 0)
            ->whereNotNull('LINE_DELIVERY_DATE')
            ->whereNotNull('EMAIL_ADDRESS')
            ->where('EMAIL_ADDRESS', '!=', '');

        VwOPENORDERSView::excludeCancelled($query);

        $rows = $query->get([
            'ENTITY',
            'REFERENCE_NBR',
            'REFERENCE_DUPLICATE',
            'CUSTOMER_CODE',
            'CUSTOMER_NAME',
            'CONTACT_NAME',
            'EMAIL_ADDRESS',
            'PROJECT_NAME',
            'ADDRESS_1',
            'ADDRESS_2',
            'SUBURB',
            'STATE',
            'POSTCODE',
            'STOCK_CODE',
            'BACKORDER_QTY',
            'LINE_DELIVERY_DATE',
            'LINE_UNIQUE_ID',
        ]);

        return $rows
            ->groupBy(fn (object $line): string => $line->ENTITY.'|'.$line->REFERENCE_NBR)
            ->map(function (Collection $lines) use ($today, $overdueMaxDaysPast): ?OpenOrderGroup {
                $sorted = $lines->sortBy('LINE_DELIVERY_DATE')->values();
                $first = $sorted->first();
                $target = CarbonImmutable::parse($first->LINE_DELIVERY_DATE)->startOfDay();
                $daysToDelivery = (int) $today->diffInDays($target, false);
                $stage = $this->stageForDays($daysToDelivery, $overdueMaxDaysPast);

                if ($stage === null) {
                    return null;
                }

                $email = $this->normalizeEmail((string) $first->EMAIL_ADDRESS);
                if ($email === null) {
                    return null;
                }

                $lineSummaries = $sorted->map(fn (object $line): array => [
                    'line_unique_id' => (int) $line->LINE_UNIQUE_ID,
                    'stock_code' => (string) $line->STOCK_CODE,
                    'description' => null,
                    'outstanding_qty' => (float) $line->BACKORDER_QTY,
                    'delivery_date' => CarbonImmutable::parse($line->LINE_DELIVERY_DATE)->toDateString(),
                ])->all();

                return new OpenOrderGroup(
                    entity: (string) $first->ENTITY,
                    referenceNbr: (string) $first->REFERENCE_NBR,
                    referenceDuplicate: (int) $first->REFERENCE_DUPLICATE,
                    customerCode: $first->CUSTOMER_CODE !== null ? (string) $first->CUSTOMER_CODE : null,
                    customerName: $first->CUSTOMER_NAME !== null ? (string) $first->CUSTOMER_NAME : null,
                    contactName: $first->CONTACT_NAME !== null ? (string) $first->CONTACT_NAME : null,
                    recipientEmail: $email,
                    projectName: $first->PROJECT_NAME !== null ? (string) $first->PROJECT_NAME : null,
                    address1: $first->ADDRESS_1 !== null ? (string) $first->ADDRESS_1 : null,
                    address2: $first->ADDRESS_2 !== null ? (string) $first->ADDRESS_2 : null,
                    suburb: $first->SUBURB !== null ? (string) $first->SUBURB : null,
                    state: $first->STATE !== null ? (string) $first->STATE : null,
                    postcode: $first->POSTCODE !== null ? (string) $first->POSTCODE : null,
                    stage: $stage,
                    targetDeliveryDate: $target,
                    daysToDelivery: $daysToDelivery,
                    totalOutstandingQty: (float) $lines->sum(
                        fn (object $line): float => (float) $line->BACKORDER_QTY,
                    ),
                    lines: $lineSummaries,
                );
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, OpenOrderGroup>
     */
    public function unsentCandidates(?CarbonImmutable $today = null): Collection
    {
        $existingLookup = array_fill_keys(
            DeliveryConfirmationSend::query()
                ->get(['entity', 'reference_nbr', 'stage'])
                ->map(fn (DeliveryConfirmationSend $row): string => $row->entity.'|'.$row->reference_nbr.'|'.$row->stage->value)
                ->all(),
            true,
        );

        return $this->candidates($today)
            ->reject(fn (OpenOrderGroup $group): bool => isset(
                $existingLookup[$group->entity.'|'.$group->referenceNbr.'|'.$group->stage->value]
            ))
            ->values();
    }

    private function stageForDays(int $daysToDelivery, int $overdueMaxDaysPast): ?DeliveryConfirmationStage
    {
        if ($daysToDelivery >= 15 && $daysToDelivery <= 28) {
            return DeliveryConfirmationStage::Weeks34;
        }

        if ($daysToDelivery >= 0 && $daysToDelivery <= 14) {
            return DeliveryConfirmationStage::Weeks12;
        }

        if ($daysToDelivery <= -1 && $daysToDelivery >= -$overdueMaxDaysPast) {
            return DeliveryConfirmationStage::Overdue;
        }

        return null;
    }

    private function normalizeEmail(string $value): ?string
    {
        $email = Str::lower(trim($value));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }
}
