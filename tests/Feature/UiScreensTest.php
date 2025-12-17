<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UiScreensTest extends TestCase
{
    /**
     * Переопределяем конфигурацию для тестов, чтобы не использовать БД для сессий.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Храним сессии в памяти, чтобы не трогать SQLite
        config(['session.driver' => 'array']);
    }
    /**
     * Тест UI: страница графиков доступна для авторизованного пользователя и содержит ключевые элементы.
     */
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

    /**
     * Тест UI: админ-страница управления ценными бумагами показывает список и кнопки действий.
     */
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

    /**
     * Тест UI: экспорт в Excel возвращает корректный CSV-ответ с заголовками.
     */
    public function test_export_excel_returns_csv_with_predictions(): void
    {
        $admin = new User([
            'login' => 'admin',
            'first_name' => 'Администратор',
            'last_name' => 'Системы',
        ]);

        config(['filesystems.default' => 'local']);
        Storage::fake('local');

        // Подготавливаем минимальный CSV для сервиса предсказаний
        $csvPath = 'securities/SBER.csv';
        $csvContent = implode("\n", [
            'ticker,time,open,high,low,close,volume',
            'SBER,2025-01-01 10:00:00,100,110,90,105,1000',
            'SBER,2025-01-02 10:00:00,105,115,95,110,1500',
        ]);

        Storage::disk('local')->put($csvPath, $csvContent);

        $response = $this->actingAs($admin)->get(
            route('securities.export', ['ticker' => 'SBER', 'format' => 'excel'])
        );

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition');
        $response->assertSee('Ценная бумага;Текущая цена;Прогноз на 1 день;Прогноз на 252 дня;Рекомендация');
    }
}


