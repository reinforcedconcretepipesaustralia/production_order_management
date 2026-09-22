<?php

use App\Enums\AppRole;
use App\Enums\Capability;
use App\Support\Authorization\ShieldRoleCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(ShieldRoleCatalog::class)->sync();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Role::query()
            ->whereIn('name', array_column(AppRole::cases(), 'value'))
            ->delete();

        Permission::query()
            ->whereIn('name', array_map(
                fn (Capability $capability): string => $capability->shieldPermission(),
                Capability::cases(),
            ))
            ->delete();
    }
};
