<?php

namespace App\Services\Planning;

use App\Models\Depot;
use App\Models\PlanRun;
use App\Models\ProductionSchedule;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Explains a plan in plain English.
 *
 * The numbers stay deterministic — everything here reads the metrics the engine already computed and
 * the exceptions it already recorded. The narrative is advisory text on the plan run and never writes
 * a quantity, so a plan is complete and correct whether or not this ever runs.
 *
 * Only the summary is sent, never the plan's hundreds of lines: a planner's question is answered from
 * the stored reason codes and metrics, and sending the whole plan would cost a great deal to tell
 * Claude what the summary already says.
 */
final class PlanNarrator
{
    private const SYSTEM = <<<'PROMPT'
        You explain production plans to the planners and plant managers of an Australian manufacturer
        of steel-reinforced concrete pipe, across five plants: Melton and Kilmore in Victoria,
        Somersby and Thornton in New South Wales, and Yatala in Queensland.

        You are given a plan's summary figures. Explain what the plan does and, more importantly,
        what it could not do and why. Be specific and quantitative — name plants, pipe counts and
        tonnes. A planner reads this to decide where to intervene, so lead with what needs a decision.

        Rules:
        - Use only the figures given. Never estimate, extrapolate or invent a number.
        - Never recommend a quantity to make. That is the planner's call on the plan's own lines.
        - Australian English. Tonnes, not tons. No headings, no bullet lists, no bold.
        - Three short paragraphs at most, and fewer if there is less to say.
        - If a plant is saturated while another sits idle, say so plainly: that is the most useful
          thing you can tell a planner.
        PROMPT;

    /**
     * Write the narrative onto the run, and return it.
     *
     * Returns null when the API is not configured or the call fails — a plan without a narrative is
     * still a plan, so a failure here is logged and never raised.
     */
    public function narrate(PlanRun $run): ?string
    {
        $apiKey = (string) config('claude.api_key');

        if ($apiKey === '') {
            return null;
        }

        $narrative = $this->ask($apiKey, $this->briefFor($run));

        if ($narrative !== null) {
            $run->update(['narrative' => $narrative]);
        }

        return $narrative;
    }

    /**
     * Everything Claude is told about the plan: the metrics, and the handful of exceptions that
     * explain the shortfalls.
     *
     * @return array<string, mixed>
     */
    public function briefFor(PlanRun $run): array
    {
        $metrics = $run->metrics ?? [];

        return [
            'horizon' => [
                'from' => $run->horizon_from->toDateString(),
                'to' => $run->horizon_to->toDateString(),
                'demand_as_at' => $run->as_of_date->toDateString(),
            ],
            'parameters' => $run->parameters ?? [],
            'totals' => [
                'lines' => $metrics['lines'] ?? 0,
                'pipes' => $metrics['planned_eaches'] ?? 0,
                'tonnes' => $metrics['planned_tonnes'] ?? 0,
                'wire_tonnes' => $metrics['wire_tonnes'] ?? 0,
                'concrete_m3' => $metrics['concrete_m3'] ?? 0,
                'unmet_ordered_pipes' => collect($metrics['by_depot'] ?? [])
                    ->sum(fn ($figures): int => (int) ($figures['unmet_order_eaches'] ?? 0)),
            ],
            'by_plant' => $this->byPlant($run, $metrics),
            'by_month' => $metrics['by_month'] ?? [],
            'wire_by_gauge_kg' => $metrics['wire_by_gauge_kg'] ?? [],
            'most_overdue_orders' => $this->overdueOrders($run),
            'reasons_used' => $this->reasonsUsed($run),
        ];
    }

    /**
     * Per-plant figures with the capacity utilisation made explicit, because a plant at 148 of 148
     * shifts with work left over is the single most decision-worthy fact in a plan.
     *
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function byPlant(PlanRun $run, array $metrics): array
    {
        $byPlant = $metrics['by_depot'] ?? [];

        if (! is_array($byPlant)) {
            return [];
        }

        $names = Depot::query()->pluck('name', 'code');

        foreach ($byPlant as $code => $figures) {
            if (! is_array($figures)) {
                continue;
            }

            $used = (int) ($figures['shift_slots_used'] ?? 0);
            $available = (int) ($figures['shift_slots'] ?? 0);

            $byPlant[$code]['plant'] = $names[$code] ?? $code;
            $byPlant[$code]['shift_utilisation_percent'] = $available === 0
                ? null
                : (int) round($used / $available * 100);
        }

        return $byPlant;
    }

    /**
     * The oldest unfilled orders, which are what a shortfall actually means to a customer.
     *
     * @return list<array<string, mixed>>
     */
    private function overdueOrders(PlanRun $run): array
    {
        return SalesOrder::query()
            ->with(['depot', 'sku'])
            ->where('status', 'open')
            ->where('requested_delivery_date', '<', $run->horizon_from)
            ->orderBy('requested_delivery_date')
            ->limit(5)
            ->get()
            ->map(fn (SalesOrder $order): array => [
                'plant' => $order->depot?->code,
                'product' => $order->sku?->sku_code,
                'pipes' => $order->ordered_quantity,
                'due' => $order->requested_delivery_date->toDateString(),
            ])
            ->all();
    }

    /**
     * Which reasons the engine gave, and how much work each accounts for. This is what lets an
     * answer be grounded in why a line is there rather than guessed.
     *
     * @return array<string, int>
     */
    private function reasonsUsed(PlanRun $run): array
    {
        return ProductionSchedule::query()
            ->where('plan_run_id', $run->id)
            ->selectRaw('reason_code, SUM(planned_quantity) AS pipes')
            ->groupBy('reason_code')
            ->pluck('pipes', 'reason_code')
            ->mapWithKeys(fn ($pipes, $reason): array => [(string) ($reason ?: 'unspecified') => (int) $pipes])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    private function ask(string $apiKey, array $brief): ?string
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => (string) config('claude.version'),
                // Routes around a safety refusal rather than returning nothing.
                'anthropic-beta' => 'server-side-fallback-2026-07-01',
            ])
                ->timeout((int) config('claude.timeout'))
                ->asJson()
                ->post(rtrim((string) config('claude.base_url'), '/').'/v1/messages', [
                    'model' => (string) config('claude.model'),
                    'max_tokens' => (int) config('claude.max_tokens'),
                    'system' => self::SYSTEM,
                    'output_config' => ['effort' => (string) config('claude.effort')],
                    'fallbacks' => 'default',
                    'messages' => [[
                        'role' => 'user',
                        'content' => "Explain this production plan.\n\n".json_encode(
                            $brief,
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                        ),
                    ]],
                ]);
        } catch (\Throwable $exception) {
            Log::warning('Could not reach Claude to narrate the plan.', ['error' => $exception->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('Claude declined to narrate the plan.', [
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            return null;
        }

        return $this->textFrom($response->json() ?? []);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function textFrom(array $payload): ?string
    {
        // A refusal returns HTTP 200 with no usable content, so the stop reason is checked before the
        // content is read.
        if (($payload['stop_reason'] ?? null) === 'refusal') {
            Log::warning('Claude refused to narrate the plan.', [
                'category' => $payload['stop_details']['category'] ?? null,
            ]);

            return null;
        }

        $text = collect($payload['content'] ?? [])
            ->filter(fn ($block): bool => is_array($block) && ($block['type'] ?? null) === 'text')
            ->map(fn (array $block): string => (string) ($block['text'] ?? ''))
            ->implode("\n");

        return trim($text) === '' ? null : trim($text);
    }
}
