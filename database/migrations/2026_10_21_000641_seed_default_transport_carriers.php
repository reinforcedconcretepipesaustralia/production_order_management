<?php

use App\Models\TransportCarrier;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->carrier('TODDS', 'Todds', pickup: false, kind: 'semi');
        $this->carrier('DC', 'DC', pickup: false, kind: 'semi');
        $this->carrier('EX_WORKS', 'Ex Works', pickup: true, kind: 'other');
    }

    public function down(): void
    {
        TransportCarrier::query()
            ->whereIn('code', ['TODDS', 'DC', 'EX_WORKS'])
            ->delete();
    }

    private function carrier(string $code, string $name, bool $pickup, string $kind): void
    {
        $carrier = TransportCarrier::query()->firstOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'is_active' => true,
                'is_pickup' => $pickup,
            ],
        );

        if ($carrier->vehicles()->exists()) {
            return;
        }

        $minimums = config('logistics.default_minimum_billable_tonnes', []);

        $carrier->vehicles()->create([
            'name' => $pickup ? 'Customer pickup' : 'Semi',
            'vehicle_kind' => $kind,
            'capacity_tonnes' => $pickup ? 0 : config('logistics.default_load_capacity_tonnes', 24),
            'minimum_billable_tonnes' => $pickup ? 0 : ($minimums[$kind] ?? 0),
            'is_default' => true,
        ]);
    }
};
