<?php

namespace Tests\Feature;

use App\Enums\AppRole;
use App\Filament\Resources\Depots\DepotResource;
use App\Filament\Resources\DowntimeEvents\DowntimeEventResource;
use App\Filament\Resources\DowntimeEvents\Pages\CreateDowntimeEvent;
use App\Filament\Resources\MachineAssets\MachineAssetResource;
use App\Filament\Resources\MachineAssets\Pages\CreateMachineAsset;
use App\Filament\Resources\NonProductionDays\NonProductionDayResource;
use App\Filament\Resources\OeeSettings\OeeSettingResource;
use App\Filament\Resources\Shifts\Pages\CreateShift;
use App\Filament\Resources\Shifts\Pages\EditShift;
use App\Filament\Resources\Shifts\ShiftResource;
use App\Models\Depot;
use App\Models\DepotShiftPattern;
use App\Models\DowntimeEvent;
use App\Models\MachineAsset;
use App\Models\NonProductionDay;
use App\Models\OeeSetting;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The plant configuration screens are how hours, shifts, machines and downtime get changed
 * without a deployment, so they are covered for access, listing and saving.
 */
class PlantConfigurationResourcesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string}>
     */
    public static function resources(): array
    {
        return [
            'plants' => [DepotResource::class],
            'shifts' => [ShiftResource::class],
            'machines' => [MachineAssetResource::class],
            'oee settings' => [OeeSettingResource::class],
            'shutdowns' => [NonProductionDayResource::class],
            'downtime' => [DowntimeEventResource::class],
        ];
    }

    #[DataProvider('resources')]
    public function test_planner_admin_can_list_and_manage_each_screen(string $resource): void
    {
        $this->actingAs($this->userWithRoles(AppRole::PlannerAdmin));

        $this->assertTrue($resource::canAccess());
        $this->assertTrue($resource::canCreate());

        $this->get($resource::getUrl('index'))->assertSuccessful();
    }

    public function test_plants_list_heading_reads_plants_not_depots(): void
    {
        $this->actingAs($this->userWithRoles(AppRole::PlannerAdmin));

        $html = $this->get(DepotResource::getUrl('index'))
            ->assertSuccessful()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<h1 class="fi-header-heading">\s*Plants\s*<\/h1>/',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<h1 class="fi-header-heading">\s*Depots\s*<\/h1>/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<a[^>]*class="fi-breadcrumbs-item-label"[^>]*>\s*Plants\s*<\/a>/',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<a[^>]*class="fi-breadcrumbs-item-label"[^>]*>\s*Depots\s*<\/a>/',
            $html,
        );
    }

    /**
     * Mounting the create page builds the whole form schema, so this catches a misused
     * component before it reaches a planner.
     */
    #[DataProvider('resources')]
    public function test_each_create_form_renders(string $resource): void
    {
        $this->actingAs($this->userWithRoles(AppRole::PlannerAdmin));
        Depot::factory()->create();

        $this->get($resource::getUrl('create'))->assertSuccessful();
    }

    #[DataProvider('resources')]
    public function test_each_edit_form_renders(string $resource): void
    {
        $this->actingAs($this->userWithRoles(AppRole::PlannerAdmin));
        $record = $this->existingRecordFor($resource);

        $this->get($resource::getUrl('edit', ['record' => $record]))->assertSuccessful();
    }

    #[DataProvider('resources')]
    public function test_depot_operator_cannot_reach_plant_configuration(string $resource): void
    {
        $this->actingAs($this->userWithRoles(AppRole::DepotOperator));

        $this->assertFalse($resource::canAccess());
        $this->assertFalse($resource::canCreate());
    }

    #[DataProvider('resources')]
    public function test_executive_can_read_but_not_change(string $resource): void
    {
        $user = $this->userWithRoles(AppRole::ViewerExecutive);
        $this->actingAs($user);

        $this->assertTrue($resource::canAccess());
        $this->assertFalse($resource::canCreate());
    }

    public function test_a_new_shift_can_be_created_with_hours_per_weekday(): void
    {
        $this->actingAs($this->userWithRoles(AppRole::PlannerAdmin));
        $depot = Depot::factory()->create(['code' => 'SOM', 'name' => 'Somersby']);

        Livewire::test(CreateShift::class)
            ->fillForm([
                'depot_id' => $depot->id,
                'code' => 'AFT',
                'name' => 'Afternoon Shift',
                'starts_at' => '16:00',
                'duration_minutes' => 600,
                'performance_target_percent' => 87,
                'availability_target_percent' => 87,
                'prestart_minutes' => 15,
                'cleaning_minutes' => 45,
                'is_active' => true,
                'patterns' => [
                    ['weekday' => 1, 'duration_minutes' => 600, 'is_active' => true],
                    ['weekday' => 2, 'duration_minutes' => 720, 'is_active' => true],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $shift = Shift::query()->where('depot_id', $depot->id)->where('code', 'AFT')->sole();

        $this->assertSame(600, $shift->durationMinutesForWeekday(1));
        $this->assertSame(720, $shift->durationMinutesForWeekday(2));
        // A weekday with no row does not run, which is how weekends stay out of the plan.
        $this->assertNull($shift->durationMinutesForWeekday(6));
    }

    public function test_pattern_depot_is_derived_from_the_shift(): void
    {
        $depot = Depot::factory()->create();
        $shift = Shift::factory()->for($depot)->create();

        // The shift form's repeater has no depot field of its own to submit.
        $pattern = DepotShiftPattern::query()->create([
            'shift_id' => $shift->id,
            'weekday' => 3,
            'duration_minutes' => 720,
        ]);

        $this->assertSame($depot->id, $pattern->fresh()->depot_id);
    }

    public function test_shift_hours_can_be_changed_for_a_single_weekday(): void
    {
        $this->actingAs($this->userWithRoles(AppRole::PlannerAdmin));
        $depot = Depot::factory()->create();
        $shift = Shift::factory()->for($depot)->create(['duration_minutes' => 600]);
        DepotShiftPattern::query()->create([
            'shift_id' => $shift->id,
            'weekday' => 3,
            'duration_minutes' => 600,
        ]);

        Livewire::test(EditShift::class, ['record' => $shift->getRouteKey()])
            ->fillForm([
                'patterns' => [
                    ['weekday' => 3, 'duration_minutes' => 720, 'is_active' => true],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(720, $shift->fresh()->load('patterns')->durationMinutesForWeekday(3));
    }

    public function test_a_new_machine_can_be_added_to_a_plant(): void
    {
        $this->actingAs($this->userWithRoles(AppRole::PlannerAdmin));
        $depot = Depot::factory()->create(['code' => 'MEL']);

        Livewire::test(CreateMachineAsset::class)
            ->fillForm([
                'depot_id' => $depot->id,
                'code' => 'MEL-BIDI84',
                'name' => 'BiDi 84',
                'machine_type' => 'bidi',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('machine_assets', [
            'depot_id' => $depot->id,
            'code' => 'MEL-BIDI84',
            'name' => 'BiDi 84',
        ]);
    }

    public function test_machine_codes_stay_unique_across_plants(): void
    {
        $this->actingAs($this->userWithRoles(AppRole::PlannerAdmin));
        $depot = Depot::factory()->create();
        MachineAsset::factory()->create(['code' => 'MEL-BIDI84']);

        Livewire::test(CreateMachineAsset::class)
            ->fillForm([
                'depot_id' => $depot->id,
                'code' => 'MEL-BIDI84',
                'name' => 'Duplicate',
                'machine_type' => 'bidi',
            ])
            ->call('create')
            ->assertHasFormErrors(['code']);
    }

    public function test_downtime_must_end_after_it_starts(): void
    {
        $this->actingAs($this->userWithRoles(AppRole::PlannerAdmin));
        $depot = Depot::factory()->create();

        Livewire::test(CreateDowntimeEvent::class)
            ->fillForm([
                'depot_id' => $depot->id,
                'starts_at' => '2026-09-09 10:00',
                'ends_at' => '2026-09-09 08:00',
                'category' => 'maintenance',
                'description' => 'Head rebuild',
            ])
            ->call('create')
            ->assertHasFormErrors(['ends_at']);
    }

    private function userWithRoles(AppRole ...$roles): User
    {
        return User::factory()->create([
            'app_roles' => array_map(fn (AppRole $role): string => $role->value, $roles),
        ]);
    }

    /**
     * A saved record of the resource's model, with the relations its form reads populated.
     */
    private function existingRecordFor(string $resource): Model
    {
        $depot = Depot::factory()->create();

        return match ($resource) {
            DepotResource::class => $depot,
            ShiftResource::class => Shift::factory()->for($depot)->create(),
            MachineAssetResource::class => MachineAsset::factory()->for($depot)->create(),
            OeeSettingResource::class => OeeSetting::factory()->create(['depot_id' => $depot->id]),
            NonProductionDayResource::class => NonProductionDay::factory()->for($depot)->create(),
            DowntimeEventResource::class => DowntimeEvent::factory()->for($depot)->create(),
        };
    }
}
