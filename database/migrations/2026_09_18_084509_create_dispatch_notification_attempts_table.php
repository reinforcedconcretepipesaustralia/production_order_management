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
        Schema::create('dispatch_notification_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_load_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('event_type', 80);
            $table->text('recipients');
            $table->string('status', 30)->default('queued');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->dateTime('attempted_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->string('provider_request_id', 255)->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('failure_category', 80)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->timestamps();

            $table->index(['dispatch_load_id', 'event_type', 'status'], 'dispatch_notification_load_event_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dispatch_notification_attempts');
    }
};
