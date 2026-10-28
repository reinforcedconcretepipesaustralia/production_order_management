<?php

namespace App\Services\Logistics;

use App\Contracts\DispatchNotificationSender;
use App\Models\DeliverySchedule;
use App\Models\DeliveryScheduleLine;
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
        $requireConfirmed = (bool) config('logistics.require_confirmed_for_carrier_email', true);

        $schedule->lines
            ->filter(function (DeliveryScheduleLine $line) use ($requireConfirmed): bool {
                if ($line->transport_carrier_id === null) {
                    return false;
                }

                return ! $requireConfirmed || $line->is_confirmed;
            })
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
                    htmlBody: $this->htmlTable($lines, includeInternal: false),
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
            htmlBody: $this->htmlTable($schedule->lines, includeInternal: true),
            idempotencyKey: sprintf('delivery-schedule:%d:internal:%s', $schedule->id, now()->format('Y-m-d-Hi')),
        ));

        return $recipients;
    }

    /**
     * @param  Collection<int, DeliveryScheduleLine>  $lines
     */
    private function htmlTable(Collection $lines, bool $includeInternal): string
    {
        $headers = ['SO#', 'Customer', 'Order#', 'Contact', 'Phone', 'Delivery name', 'Suburb', 'Zone', 'Pipe', 'Balance', 'SOH', 'Qty', 'Tonnage', 'Delayed', 'Notes'];

        if ($includeInternal) {
            $headers = array_merge($headers, ['Transport', 'Confirmed', 'Est. freight']);
        }

        $rows = $lines->sortBy('sort_order')->map(function (DeliveryScheduleLine $line) use ($includeInternal): string {
            $cells = [
                $line->erp_order_reference,
                $line->customer_name,
                $line->customer_order,
                $line->contact_name,
                $line->phone,
                $line->delivery_name,
                $line->suburb,
                $line->zone_code,
                $line->stock_code,
                (string) $line->backorder_qty,
                (string) $line->prime_soh,
                (string) $line->dispatch_qty,
                number_format((float) $line->tonnage, 3),
                $line->is_delayed ? ($line->delay_reason ?: 'Yes') : 'No',
                $line->notes,
            ];

            if ($includeInternal) {
                $cells[] = $line->transportCarrier?->name;
                $cells[] = $line->is_confirmed ? 'Yes' : 'No';
                $cells[] = $line->estimated_freight_amount !== null
                    ? number_format((float) $line->estimated_freight_amount, 2)
                    : '';
            }

            return '<tr>'.collect($cells)->map(fn (mixed $value): string => '<td>'.e((string) ($value ?? '')).'</td>')->implode('').'</tr>';
        })->implode('');

        $head = collect($headers)->map(fn (string $header): string => '<th>'.e($header).'</th>')->implode('');

        return '<table border="1" cellpadding="6" cellspacing="0"><thead><tr>'.$head.'</tr></thead><tbody>'.$rows.'</tbody></table>';
    }
}
