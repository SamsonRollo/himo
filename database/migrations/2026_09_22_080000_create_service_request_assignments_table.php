<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only assignment log: records every staff assignment independently
        // of the mutable service_requests.assigned_to column, so a staff member's
        // task history survives a future reassignment even after assigned_to moves
        // to someone else.
        Schema::create('service_request_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('unassigned_at')->nullable();
            $table->index(['staff_id', 'assigned_at']);
            $table->index(['service_request_id', 'assigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_request_assignments');
    }
};
