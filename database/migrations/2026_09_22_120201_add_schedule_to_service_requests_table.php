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
     * Nullable at the DB level so this stays additive against the 700
     * existing rows (no default makes sense for a requested schedule that
     * was never captured); "required" for new/edited requests is enforced
     * in the model's saving() validation instead, the same way
     * service_category_id/location/description already are.
     */
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            // Requester's original ask — never silently overwritten.
            $table->timestamp('needed_start_at')->nullable()->after('priority');
            $table->timestamp('needed_end_at')->nullable()->after('needed_start_at');
            // Supervisor's final assigned schedule; defaults to the
            // requester's needed_* at assignment time but may be changed
            // (logged via activity_logs, see ActivityLog::record()).
            $table->timestamp('scheduled_start_at')->nullable()->after('needed_end_at');
            $table->timestamp('scheduled_end_at')->nullable()->after('scheduled_start_at');
            $table->index(['scheduled_start_at', 'scheduled_end_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropColumn(['needed_start_at', 'needed_end_at', 'scheduled_start_at', 'scheduled_end_at']);
        });
    }
};
