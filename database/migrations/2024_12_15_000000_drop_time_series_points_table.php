<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('time_series_points');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Восстановление таблицы, если нужно откатить миграцию
        Schema::create('time_series_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')
                ->constrained('stocks')
                ->cascadeOnDelete();

            $table->unsignedInteger('interval');
            $table->dateTime('time');

            $table->decimal('open', 15, 6);
            $table->decimal('high', 15, 6);
            $table->decimal('low', 15, 6);
            $table->decimal('close', 15, 6);
            $table->bigInteger('volume');

            $table->timestamps();

            $table->unique(['stock_id', 'interval', 'time'], 'time_series_unique_point');
        });
    }
};

