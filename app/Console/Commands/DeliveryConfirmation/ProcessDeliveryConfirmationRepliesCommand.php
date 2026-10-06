<?php

namespace App\Console\Commands\DeliveryConfirmation;

use App\Services\DeliveryConfirmation\DeliveryConfirmationReplyService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ProcessDeliveryConfirmationRepliesCommand extends Command
{
    protected $signature = 'delivery-reminders:process-replies
        {--date= : Evaluate the two-week approval rule as of this Y-m-d date}';

    protected $description = 'Read delivery-reminder replies and confirm, amend, or hold lines for sales-rep approval';

    public function handle(DeliveryConfirmationReplyService $service): int
    {
        $today = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'), 'Australia/Melbourne')->startOfDay()
            : null;

        $counts = $service->processInbox($today);

        $this->info(sprintf(
            'Delivery reminder replies: processed=%d confirmed=%d written=%d pending_approval=%d unresolved=%d',
            $counts['processed'],
            $counts['confirmed'],
            $counts['written'],
            $counts['pending_approval'],
            $counts['unresolved'],
        ));

        return self::SUCCESS;
    }
}
