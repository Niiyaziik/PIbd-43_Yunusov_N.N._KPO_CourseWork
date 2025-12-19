<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UiScreensTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'array']);
    }

    public function test_securities_index_page_shows_main_ui_elements(): void
    {
        $user = new User([
            'login' => 'user1',
            'first_name' => 'Иван',
            'last_name' => 'Иванов',
        ]);

        $response = $this->actingAs($user)->get(route('securities.index'));

        $response->assertStatus(200);
        $response->assertSee('Графики российских акций (MOEX)');
        $response->assertSee('Ценная бумага');
        $response->assertSee('Скачать Excel');
        $response->assertSee('Скачать PDF');
    }

    public function test_admin_stocks_page_shows_stocks_and_actions(): void
    {
        $admin = new User([
            'login' => 'admin',
            'first_name' => 'Администратор',
            'last_name' => 'Системы',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.stocks'));

        $response->assertStatus(200);
        $response->assertSee('Управление ценными бумагами');
        $response->assertSee('Список ценных бумаг');
        $response->assertSee('Нет доступных ценных бумаг');
    }
}
