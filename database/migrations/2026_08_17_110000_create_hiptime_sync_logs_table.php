<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hiptime_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('received')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->json('unmapped_enroll_numbers')->nullable();
            $table->json('unmapped_ids')->nullable();
            $table->json('errors')->nullable();
            $table->string('message')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hiptime_sync_logs');
    }
};
