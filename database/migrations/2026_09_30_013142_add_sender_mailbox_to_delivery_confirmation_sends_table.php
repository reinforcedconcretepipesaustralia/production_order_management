<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_confirmation_sends', function (Blueprint $table) {
            $table->string('sender_mailbox')->nullable()->after('recipient_email');
            $table->index('sender_mailbox');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_confirmation_sends', function (Blueprint $table) {
            $table->dropIndex(['sender_mailbox']);
            $table->dropColumn('sender_mailbox');
        });
    }
};
