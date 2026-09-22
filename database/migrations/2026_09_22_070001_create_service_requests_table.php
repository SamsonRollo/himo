<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no', 50)->unique();
            $table->foreignId('service_category_id')->constrained()->restrictOnDelete();
            $table->string('location', 150);
            $table->text('description');
            $table->enum('status', ['submitted', 'assigned', 'in_progress', 'for_confirmation', 'completed'])->default('submitted')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('completion_note')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            // The authenticated creator is also the requester; no duplicate owner column.
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE service_requests ADD CONSTRAINT service_requests_completion_check CHECK (status <> 'completed' OR (assigned_to IS NOT NULL AND completion_note IS NOT NULL AND completion_note ~ '[^[:space:]]'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};
