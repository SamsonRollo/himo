<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_restores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_backup_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('completed');
            $table->text('error_message')->nullable();
            $table->foreignId('restored_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_restores');
    }
};
