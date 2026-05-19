<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('work_order_extra_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->string('name', 255);
            $table->string('unit', 50)->nullable();
            $table->decimal('qty', 12, 2)->default(0);
            $table->decimal('rate', 12, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('note', 500)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('work_order_id', 'woei_work_order_fk')
                ->references('id')->on('work_orders')->cascadeOnDelete();
            $table->index('work_order_id', 'woei_wo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_extra_items');
    }
};
