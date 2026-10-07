<?php

use App\Support\Authorization\ShieldRoleCatalog;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(ShieldRoleCatalog::class)->sync();
    }

    public function down(): void
    {
        app(ShieldRoleCatalog::class)->sync();
    }
};
