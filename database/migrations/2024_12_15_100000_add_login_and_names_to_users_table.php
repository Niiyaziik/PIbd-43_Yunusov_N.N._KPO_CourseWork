<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Проверяем, существует ли колонка login
        if (! Schema::hasColumn('users', 'login')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('login')->nullable()->unique()->after('id');
            });

            // Заполняем login для существующих пользователей (если есть)
            // Используем email как login, если login пустой
            DB::statement('UPDATE users SET login = email WHERE login IS NULL');

            // Теперь делаем login обязательным
            Schema::table('users', function (Blueprint $table) {
                $table->string('login')->nullable(false)->change();
            });
        }

        if (! Schema::hasColumn('users', 'first_name')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('first_name')->nullable()->after('name');
            });
        }

        if (! Schema::hasColumn('users', 'last_name')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('last_name')->nullable()->after('first_name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['login', 'first_name', 'last_name']);
        });
    }
};
