<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;
    public function test_admin_add_ticker_api_creates_stock(): void
    {
        $admin = User::factory()->create([
            'login' => 'admin',
        ]);

        Http::fake([
            'iss.moex.com/*' => Http::response([
                'securities' => [
                    'columns' => ['SECID', 'SHORTNAME', 'ISIN'],
                    'data' => [['GAZP', 'Газпром', 'RU0007661625']],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($admin)->post('/admin/stocks/add', [
            'ticker' => 'GAZP',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('stocks', [
            'ticker' => 'GAZP',
        ]);
    }

    public function test_admin_delete_stock_api_removes_stock(): void
    {
        $admin = User::factory()->create([
            'login' => 'admin',
        ]);

        $stock = Stock::factory()->create([
            'ticker' => 'SBER',
        ]);

        $response = $this->actingAs($admin)->delete("/admin/stocks/{$stock->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('stocks', [
            'id' => $stock->id,
        ]);
    }
}

