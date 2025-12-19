<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_securities_history_returns_json_with_data(): void
    {
        $user = User::factory()->create();
        $stock = Stock::factory()->create([
            'ticker' => 'SBER',
            'is_available' => true,
        ]);

        Http::fake([
            'iss.moex.com/*' => Http::response([
                'candles' => [
                    'columns' => ['open', 'close', 'high', 'low', 'volume', 'begin'],
                    'data' => [
                        ['100.5', '105.0', '110.0', '95.0', '1000', '2025-01-01 10:00:00'],
                        ['105.0', '110.0', '115.0', '100.0', '1500', '2025-01-02 10:00:00'],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user)->getJson('/api/securities/SBER?interval=24');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ticker',
            'interval',
            'from',
            'count',
            'points' => [
                '*' => ['time', 'open', 'high', 'low', 'close', 'volume'],
            ],
        ]);
        $response->assertJson(['ticker' => 'SBER']);
    }

    public function test_api_securities_history_returns_error_for_unknown_ticker(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/securities/UNKNOWN');

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Unknown ticker']);
    }


    public function test_api_admin_check_model_status_returns_json(): void
    {
        $admin = User::factory()->create([
            'login' => 'admin',
        ]);

        $response = $this->actingAs($admin)->getJson('/admin/stocks/SBER/model-status');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ticker',
            'is_ready',
            'model_exists',
            'scaler_exists',
        ]);
        $response->assertJson(['ticker' => 'SBER']);
    }
}

