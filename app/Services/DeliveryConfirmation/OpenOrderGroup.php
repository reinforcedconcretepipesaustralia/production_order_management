<?php

namespace App\Services\DeliveryConfirmation;

use App\Enums\DeliveryConfirmationStage;
use Carbon\CarbonImmutable;

/**
 * One open sales order ready for a delivery-reminder email.
 *
 * @phpstan-type LineSummary array{
 *     line_unique_id: int,
 *     stock_code: string,
 *     description: ?string,
 *     outstanding_qty: float,
 *     delivery_date: string
 * }
 */
final readonly class OpenOrderGroup
{
    /**
     * @param  list<LineSummary>  $lines
     */
    public function __construct(
        public string $entity,
        public string $referenceNbr,
        public int $referenceDuplicate,
        public ?string $customerCode,
        public ?string $customerName,
        public ?string $contactName,
        public string $recipientEmail,
        public ?string $projectName,
        public ?string $address1,
        public ?string $address2,
        public ?string $suburb,
        public ?string $state,
        public ?string $postcode,
        public DeliveryConfirmationStage $stage,
        public CarbonImmutable $targetDeliveryDate,
        public int $daysToDelivery,
        public float $totalOutstandingQty,
        public array $lines,
    ) {}

    public function lineCount(): int
    {
        return count($this->lines);
    }

    public function deliveryAddress(): string
    {
        return collect([
            $this->address1,
            $this->address2,
            $this->suburb,
            $this->state,
            $this->postcode,
        ])->filter(fn (?string $part): bool => filled($part))->implode(', ');
    }
}
