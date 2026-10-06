<?php

namespace App\Services\DeliveryConfirmation;

use App\Models\DeliveryConfirmationSend;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class DeliveryConfirmationMessage
{
    public function __construct(
        public string $recipient,
        public string $subject,
        public string $htmlBody,
        public string $trackingToken,
    ) {}
}

class DeliveryConfirmationMailFactory
{
    public function make(OpenOrderGroup $group, DeliveryConfirmationSend $send): DeliveryConfirmationMessage
    {
        $token = $send->tracking_token;
        $greetingName = filled($group->contactName) ? $group->contactName : ($group->customerName ?? 'customer');
        $deliveryLabel = $group->targetDeliveryDate->format('d/m/Y');
        $address = $group->deliveryAddress();

        $lineRows = collect($group->lines)->map(function (array $line): string {
            $lineDate = CarbonImmutable::parse($line['delivery_date'])->format('d/m/Y');
            $description = filled($line['description'] ?? null) ? e((string) $line['description']) : '—';

            return '<tr>'
                .'<td>'.e((string) $line['line_unique_id']).'</td>'
                .'<td>'.e($line['stock_code']).'</td>'
                .'<td>'.$description.'</td>'
                .'<td style="text-align:right">'.e(number_format($line['outstanding_qty'], 0)).'</td>'
                .'<td>'.e($lineDate).'</td>'
                .'</tr>';
        })->implode('');

        $replyExamples = collect($group->lines)->map(function (array $line): string {
            $lineDate = CarbonImmutable::parse($line['delivery_date'])->format('d/m/Y');

            return 'LINE '.$line['line_unique_id'].' CONFIRM'
                ."\n"
                .'LINE '.$line['line_unique_id'].' NEW DATE: '.$lineDate;
        })->implode("\n");

        $html = '<p>Hello '.e($greetingName).',</p>'
            .'<p>This is a delivery reminder for RCPA sales order <strong>'.e($group->referenceNbr).'</strong>, '
            .'which is <strong>'.e($group->stage->subjectPhrase()).'</strong>.</p>'
            .'<ul>'
            .'<li><strong>Earliest line date:</strong> '.e($deliveryLabel).'</li>'
            .'<li><strong>Customer:</strong> '.e((string) ($group->customerName ?? $group->customerCode)).'</li>'
            .(filled($group->projectName) ? '<li><strong>Project:</strong> '.e($group->projectName).'</li>' : '')
            .(filled($address) ? '<li><strong>Delivery address:</strong> '.e($address).'</li>' : '')
            .'<li><strong>Outstanding quantity:</strong> '.e(number_format($group->totalOutstandingQty, 0)).'</li>'
            .'</ul>'
            .'<h3>Open lines</h3>'
            .'<table border="1" cellpadding="6" cellspacing="0">'
            .'<thead><tr><th>Line ID</th><th>Stock</th><th>Description</th><th>Outstanding</th><th>Delivery date</th></tr></thead>'
            .'<tbody>'.$lineRows.'</tbody></table>'
            .'<h3>How to reply</h3>'
            .'<p>Reply with one instruction per line. Use exactly this format:</p>'
            .'<pre style="font-family:monospace;background:#f5f5f5;padding:12px">'
            .e("LINE {id} CONFIRM\nLINE {id} NEW DATE: dd/mm/yyyy")
            .'</pre>'
            .'<p>Examples for this order:</p>'
            .'<pre style="font-family:monospace;background:#f5f5f5;padding:12px">'.e($replyExamples).'</pre>'
            .'<p>Confirm keeps the current date. A new date updates that line only. '
            .'Requests to move a line earlier while it is inside the two-week window are held for your sales representative.</p>'
            .'<p style="color:#666;font-size:12px">Tracking code: '.e($token).'</p>';

        $subject = Str::squish(sprintf(
            'RCPA delivery reminder — order %s (%s) [%s]',
            $group->referenceNbr,
            $group->stage->label(),
            $token,
        ));

        return new DeliveryConfirmationMessage(
            recipient: $group->recipientEmail,
            subject: $subject,
            htmlBody: $html,
            trackingToken: $token,
        );
    }
}
