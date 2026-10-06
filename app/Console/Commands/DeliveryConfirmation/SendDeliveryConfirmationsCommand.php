<?php

namespace App\Console\Commands\DeliveryConfirmation;

use App\Services\DeliveryConfirmation\DeliveryConfirmationSendService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendDeliveryConfirmationsCommand extends Command
{
    protected $signature = 'delivery-reminders:send
        {--force : Send even when DELIVERY_REMINDERS_ENABLED is false}
        {--date= : Evaluate windows as of this Y-m-d date (Australia/Melbourne)}';

    protected $description = 'Email customers for open orders at the 4-week, 2-week, and recent-overdue windows';

    public function handle(DeliveryConfirmationSendService $service): int
    {
        $today = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'), 'Australia/Melbourne')->startOfDay()
            : null;

        $counts = $service->sendDue($today, force: (bool) $this->option('force'));

        $this->info(sprintf(
            'Delivery reminders: sent=%d failed=%d skipped=%d',
            $counts['sent'],
            $counts['failed'],
            $counts['skipped'],
        ));

        if (! config('delivery-reminders.enabled') && ! $this->option('force')) {
            $this->comment('DELIVERY_REMINDERS_ENABLED is false. Pass --force to send anyway, or enable the flag after mark-seen.');
        }

        return self::SUCCESS;
    }
}
