<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line outcomes from a customer reply to a delivery reminder.
 *
 * A line can be confirmed, written to Tencia, held for sales-rep approval when
 * an earlier date is requested inside the two-week window, or left unresolved
 * when the reply is ambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_confirmation_line_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_confirmation_send_id')
                ->constrained('delivery_confirmation_sends')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('line_unique_id');
            $table->string('stock_code', 40)->nullable();
            $table->string('description')->nullable();
            $table->decimal('outstanding_qty', 18, 2)->default(0);
            $table->date('current_delivery_date');
            $table->date('requested_delivery_date')->nullable();
            $table->string('action', 20);
            $table->string('outcome', 40);
            $table->string('outcome_message', 500)->nullable();
            $table->timestamp('tencia_updated_at')->nullable();
            $table->boolean('needs_rep_approval')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['delivery_confirmation_send_id', 'line_unique_id'],
                'dc_line_responses_send_line_unique',
            );
            $table->index(['needs_rep_approval', 'outcome']);
            $table->index('line_unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_confirmation_line_responses');
    }
};
