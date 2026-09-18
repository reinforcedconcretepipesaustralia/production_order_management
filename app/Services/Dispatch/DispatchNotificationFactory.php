<?php

namespace App\Services\Dispatch;

use App\Models\DispatchLoad;
use App\Models\DispatchNotificationAttempt;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class DispatchNotificationFactory
{
    public function make(
        DispatchLoad $load,
        DispatchNotificationAttempt $attempt,
    ): DispatchNotification {
        $load->loadMissing(['depot', 'items.orderAllocation.salesOrder.sku']);

        $capacity = (float) $load->capacity_tonnes;
        $weight = (float) $load->current_weight_tonnes;
        $utilization = $capacity > 0 ? ($weight / $capacity) * 100 : 0;
        $dispatchAt = $load->getAttribute('dispatch_at');
        $dispatchLabel = $dispatchAt instanceof CarbonInterface
            ? $dispatchAt->timezone($load->depot->timezone)->format('d M Y, g:i A T')
            : $load->dispatch_date->format('d M Y');

        $details = [
            '<strong>Load reference:</strong> '.e($load->load_reference),
            '<strong>Depot:</strong> '.e($load->depot->code.' — '.$load->depot->name),
            '<strong>Dispatch:</strong> '.e($dispatchLabel),
            '<strong>Weight / capacity:</strong> '.number_format($weight, 3).' t / '.number_format($capacity, 3).' t',
            '<strong>Capacity utilization:</strong> '.number_format($utilization, 1).'%',
        ];

        $carrier = $load->getAttribute('carrier_name');
        $vehicle = $load->getAttribute('vehicle_registration');
        if (filled($carrier) || filled($vehicle)) {
            $details[] = '<strong>Carrier / vehicle:</strong> '.e(
                collect([$carrier, $vehicle])->filter()->implode(' / '),
            );
        }

        $orderRows = $load->items->map(function ($item): string {
            $order = $item->orderAllocation->salesOrder;

            return '<tr>'
                .'<td>'.e($order->erp_order_reference).'</td>'
                .'<td>'.e($order->customer_name).' ('.e($order->customer_code).')</td>'
                .'<td>'.e($order->delivery_address).'</td>'
                .'<td>'.e($order->sku->sku_code).'</td>'
                .'<td style="text-align:right">'.number_format($item->quantity).'</td>'
                .'</tr>';
        })->implode('');

        $htmlBody = '<p>A dispatch load is ready for coordination.</p>'
            .'<ul><li>'.implode('</li><li>', $details).'</li></ul>'
            .'<h3>Order and delivery summary</h3>'
            .'<table border="1" cellpadding="6" cellspacing="0">'
            .'<thead><tr><th>Order</th><th>Customer</th><th>Delivery</th><th>SKU</th><th>Quantity</th></tr></thead>'
            .'<tbody>'.$orderRows.'</tbody></table>';

        return new DispatchNotification(
            recipients: $attempt->recipients,
            subject: Str::squish("Dispatch load {$load->load_reference} — {$load->depot->code}"),
            htmlBody: $htmlBody,
            idempotencyKey: $attempt->idempotency_key,
        );
    }
}
