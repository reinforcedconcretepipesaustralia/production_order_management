<?php

namespace App\Console\Commands\DeliveryConfirmation;

use App\Services\DeliveryConfirmation\DeliveryConfirmationSendService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MarkDeliveryConfirmationsSeenCommand extends Command
{
    protected $signature = 'delivery-reminders:mark-seen
        {--date= : Baseline candidates as of this Y-m-d date (Australia/Melbourne)}';

    protected $description = 'Baseline current delivery-reminder candidates without emailing customers';

    public function handle(DeliveryConfirmationSendService $service): int
    {
        $today = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'), 'Australia/Melbourne')->startOfDay()
            : null;

        $counts = $service->markSeen($today);

        $this->info(sprintf(
            'Delivery reminder baseline: baselined=%d skipped=%d',
            $counts['baselined'],
            $counts['skipped'],
        ));

        return self::SUCCESS;
    }
}
