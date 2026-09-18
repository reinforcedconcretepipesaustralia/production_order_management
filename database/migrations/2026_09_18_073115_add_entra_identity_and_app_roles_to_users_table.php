<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('entra_object_id')->nullable()->after('id');
            $table->uuid('entra_tenant_id')->nullable()->after('entra_object_id');
            $table->json('app_roles')->nullable()->after('entra_tenant_id');

            $table->unique(
                ['entra_tenant_id', 'entra_object_id'],
                'users_entra_tenant_object_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_entra_tenant_object_unique');
            $table->dropColumn(['entra_object_id', 'entra_tenant_id', 'app_roles']);
        });
    }
};
