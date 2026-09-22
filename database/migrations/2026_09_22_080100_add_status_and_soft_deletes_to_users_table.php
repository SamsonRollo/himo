<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The operative "soft delete" for user accounts: Super Admin
            // deactivates rather than deletes, so historical associations
            // (created_by/assigned_to on other tables) stay intact.
            $table->string('status', 20)->default('active')->after('password');
            $table->timestamp('deactivated_at')->nullable()->after('status');
            $table->foreignId('deactivated_by')->nullable()->after('deactivated_at')->constrained('users')->nullOnDelete();
            // Defense in depth only: the User model blocks force-deletes
            // outright, so this column is never expected to be populated.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn(['status', 'deactivated_at', 'deleted_at']);
        });
    }
};
