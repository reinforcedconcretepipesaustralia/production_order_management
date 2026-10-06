<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One outbound delivery-reminder email per open sales order and reminder stage.
 *
 * Status `baseline` is written by --mark-seen so enabling the job does not email
 * the historic overdue book. Line-level confirmations and amendments live on
 * delivery_confirmation_line_responses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_confirmation_sends', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_token', 40)->unique();
            $table->string('entity', 16);
            $table->string('reference_nbr', 40);
            $table->unsignedInteger('reference_duplicate')->default(0);
            $table->string('customer_code', 40)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('recipient_email');
            $table->string('stage', 20);
            $table->date('target_delivery_date');
            $table->unsignedInteger('line_count')->default(0);
            $table->decimal('total_outstanding_qty', 18, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('failure_category', 80)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->timestamp('reply_received_at')->nullable();
            $table->string('reply_provider_message_id')->nullable();
            $table->string('reply_outcome', 40)->nullable();
            $table->string('reply_outcome_message', 500)->nullable();
            $table->timestamps();

            $table->unique(['entity', 'reference_nbr', 'stage']);
            $table->index(['status', 'stage']);
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_confirmation_sends');
    }
};
