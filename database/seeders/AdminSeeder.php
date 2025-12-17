<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Проверяем, существует ли уже админ
        $admin = User::where('login', 'admin')->first();
        
        if ($admin) {
            $this->command->info('Админ уже существует, обновляем пароль.');
            $admin->password = Hash::make('pass');
            $admin->save();
        } else {
            User::create([
                'login' => 'admin',
                'name' => 'Администратор',
                'first_name' => 'Администратор',
                'last_name' => 'Системы',
                'email' => 'admin@example.com',
                'password' => Hash::make('pass'),
            ]);
            $this->command->info('Админ создан: login=admin, password=pass');
        }
    }
}

