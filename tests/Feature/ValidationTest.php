<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidationTest extends TestCase
{
    use RefreshDatabase;


    public function test_login_validation_requires_login_field(): void
    {
        $response = $this->post('/login', [
            'password' => 'password123',
        ]);

        $response->assertSessionHasErrors('login');
    }

    public function test_login_validation_requires_password_field(): void
    {
        $response = $this->post('/login', [
            'login' => 'testuser',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_register_validation_requires_all_fields(): void
    {
        $response = $this->post('/register', []);

        $response->assertSessionHasErrors([
            'login',
            'email',
            'first_name',
            'last_name',
            'password',
        ]);
    }

    public function test_register_validation_email_must_be_valid(): void
    {
        $response = $this->post('/register', [
            'login' => 'newuser',
            'email' => 'invalid-email',
            'first_name' => 'Иван',
            'last_name' => 'Иванов',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_register_validation_email_must_be_unique(): void
    {
        User::factory()->create([
            'email' => 'existing@example.com',
        ]);

        $response = $this->post('/register', [
            'login' => 'newuser',
            'email' => 'existing@example.com',
            'first_name' => 'Иван',
            'last_name' => 'Иванов',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_register_validation_login_must_be_unique(): void
    {
        User::factory()->create([
            'login' => 'existinguser',
        ]);

        $response = $this->post('/register', [
            'login' => 'existinguser',
            'email' => 'new@example.com',
            'first_name' => 'Иван',
            'last_name' => 'Иванов',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors('login');
    }

    public function test_register_validation_password_must_be_confirmed(): void
    {
        $response = $this->post('/register', [
            'login' => 'newuser',
            'email' => 'new@example.com',
            'first_name' => 'Иван',
            'last_name' => 'Иванов',
            'password' => 'password123',
            'password_confirmation' => 'differentpassword',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_admin_add_ticker_validation_requires_ticker(): void
    {
        $admin = User::factory()->create([
            'login' => 'admin',
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/stocks/add', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('ticker');
        $response->assertJsonStructure([
            'message',
            'errors' => [
                'ticker',
            ],
        ]);
    }

    public function test_admin_add_ticker_validation_ticker_must_be_unique(): void
    {
        $admin = User::factory()->create([
            'login' => 'admin',
        ]);

        Stock::factory()->create([
            'ticker' => 'SBER',
        ]);

        $response = $this->actingAs($admin)->post('/admin/stocks/add', [
            'ticker' => 'SBER',
        ]);

        $response->assertSessionHasErrors('ticker');
    }

    public function test_admin_update_availability_validation_requires_is_available(): void
    {
        $admin = User::factory()->create([
            'login' => 'admin',
        ]);

        $stock = Stock::factory()->create();

        $response = $this->actingAs($admin)->patch("/admin/stocks/{$stock->id}", []);

        $response->assertSessionHasErrors('is_available');
    }

    public function test_admin_update_availability_validation_is_available_must_be_boolean(): void
    {
        $admin = User::factory()->create([
            'login' => 'admin',
        ]);

        $stock = Stock::factory()->create();

        $response = $this->actingAs($admin)->patch("/admin/stocks/{$stock->id}", [
            'is_available' => 'not-a-boolean',
        ]);

        $response->assertSessionHasErrors('is_available');
    }

    public function test_api_securities_history_validation_interval_must_be_valid(): void
    {
        $user = User::factory()->create();
        $stock = Stock::factory()->create([
            'ticker' => 'SBER',
            'is_available' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/securities/SBER?interval=invalid');

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Unsupported interval']);
    }
}

