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
     * Distinct from the existing `status` column (account active/inactive,
     * UserStatus): this is Service Staff availability. Present on every
     * user row for simplicity (no separate staff-profile table exists),
     * but only meaningful/displayed for service_staff-role accounts.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('staff_status', [
                'available', 'busy', 'on_leave', 'official_business',
                'in_training', 'out_of_office', 'absent', 'inactive',
            ])->default('available')->after('status')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('staff_status');
        });
    }
};
