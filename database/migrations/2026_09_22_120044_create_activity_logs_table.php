<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * Generic, append-only audit trail reused for changes that don't already
     * have a dedicated history table (schedule reassignment, staff status),
     * modeled on service_request_status_histories's shape but polymorphic
     * since it covers more than one subject type.
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');
            $table->string('action', 60);
            $table->text('from_value')->nullable();
            $table->text('to_value')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
