<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_api_successful_authentication(): void
    {
        $user = User::factory()->create([
            'login' => 'testuser',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->post('/login', [
            'login' => 'testuser',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('securities.index'));
        $this->assertAuthenticatedAs($user);
    }


    public function test_login_api_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->create([
            'login' => 'testuser',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->post('/login', [
            'login' => 'testuser',
            'password' => 'wrongpassword',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertGuest();
    }


    public function test_register_api_creates_user_and_logs_in(): void
    {
        $response = $this->post('/register', [
            'login' => 'newuser',
            'email' => 'newuser@example.com',
            'first_name' => 'Иван',
            'last_name' => 'Иванов',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('securities.index'));
        $this->assertDatabaseHas('users', [
            'login' => 'newuser',
            'email' => 'newuser@example.com',
        ]);
        $this->assertAuthenticated();
    }


    public function test_logout_api_clears_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->post('/logout');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }
}

