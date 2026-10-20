<?php

namespace App\Services\Logistics;

use App\Contracts\DispatchNotificationSender;
use App\Models\DeliverySchedule;
use App\Models\TransportCarrier;
use App\Services\Dispatch\DispatchNotification;
use Illuminate\Support\Collection;

final class DeliveryScheduleMailService
{
    public function __construct(private DispatchNotificationSender $sender) {}

    public function publish(DeliverySchedule $schedule): void
    {
        $schedule->forceFill([
            'status' => 'published',
            'published_at' => now(),
        ])->save();
    }

    /**
     * @return list<string> Recipient addresses emailed
     */
    public function sendToCarriers(DeliverySchedule $schedule): array
    {
        $schedule->load(['depot', 'lines.transportCarrier.emails']);

        $sent = [];

        $schedule->lines
            ->filter(fn ($line): bool => $line->transport_carrier_id !== null)
            ->groupBy('transport_carrier_id')
            ->each(function (Collection $lines, int|string $carrierId) use ($schedule, &$sent): void {
                $carrier = TransportCarrier::query()->with('emails')->find($carrierId);

                if ($carrier === null || $carrier->is_pickup) {
                    return;
                }

                $recipients = $carrier->emails->pluck('email')->filter()->unique()->values()->all();

                if ($recipients === []) {
                    return;
                }

                $subject = sprintf(
                    'Delivery schedule %s · %s · %s',
                    $schedule->schedule_date->format('d M Y'),
                    $schedule->depot->code,
                    $carrier->name,
                );

                $this->sender->send(new DispatchNotification(
                    recipients: $recipients,
                    subject: $subject,
                    htmlBody: $this->htmlTable($lines),
                    idempotencyKey: sprintf(
                        'delivery-schedule:%d:carrier:%d:%s',
                        $schedule->id,
                        $carrier->id,
                        now()->format('Y-m-d-Hi'),
                    ),
                ));

                array_push($sent, ...$recipients);
            });

        return array_values(array_unique($sent));
    }

    /**
     * @return list<string>
     */
    public function sendInternalCopy(DeliverySchedule $schedule): array
    {
        $recipients = config('logistics.internal_schedule_recipients', []);

        if ($recipients === []) {
            return [];
        }

        $schedule->load(['depot', 'lines.transportCarrier']);

        $subject = sprintf(
            'Internal delivery schedule %s · %s',
            $schedule->schedule_date->format('d M Y'),
            $schedule->depot->code,
        );

        $this->sender->send(new DispatchNotification(
            recipients: $recipients,
            subject: $subject,
            htmlBody: $this->htmlTable($schedule->lines),
            idempotencyKey: sprintf('delivery-schedule:%d:internal:%s', $schedule->id, now()->format('Y-m-d-Hi')),
        ));

        return $recipients;
    }

    /**
     * @param  Collection<int, \App\Models\DeliveryScheduleLine>  $lines
     */
    private function htmlTable(Collection $lines): string
    {
        $rows = $lines->sortBy('sort_order')->map(function ($line): string {
            $carrier = $line->transportCarrier?->name ?? '—';

            return '<tr>'
                .'<td>'.e($line->erp_line_reference).'</td>'
                .'<td>'.e((string) $line->dispatch_qty).'</td>'
                .'<td>'.e(number_format((float) $line->tonnage, 3)).'</td>'
                .'<td>'.e($carrier).'</td>'
                .'<td>'.($line->is_confirmed ? 'Yes' : 'No').'</td>'
                .'<td>'.e((string) ($line->notes ?? '')).'</td>'
                .'</tr>';
        })->implode('');

        return '<table border="1" cellpadding="6" cellspacing="0">'
            .'<thead><tr><th>Line</th><th>Qty</th><th>Tonnage</th><th>Transport</th><th>Confirmed</th><th>Notes</th></tr></thead>'
            .'<tbody>'.$rows.'</tbody></table>';
    }
}
