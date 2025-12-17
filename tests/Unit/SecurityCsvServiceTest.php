<?php

namespace Tests\Unit;

use App\Services\SecurityCsvService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityCsvServiceTest extends TestCase
{
    /**
     * Тест: appendData создаёт новый CSV-файл с заголовком и строками.
     */
    public function test_append_data_creates_file_with_header_and_rows(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::fake('local');

        $service = new SecurityCsvService();

        $points = [
            [
                'time' => '2025-01-01 10:00:00',
                'open' => 100.0,
                'high' => 110.0,
                'low' => 90.0,
                'close' => 105.0,
                'volume' => 1000,
            ],
            [
                'time' => '2025-01-02 10:00:00',
                'open' => 105.0,
                'high' => 115.0,
                'low' => 95.0,
                'close' => 110.0,
                'volume' => 1500,
            ],
        ];

        $added = $service->appendData('SBER', $points);

        $this->assertSame(2, $added, 'Ожидаем, что будут добавлены две строки.');

        $path = 'securities/SBER.csv';

        $this->assertTrue(
            Storage::disk('local')->exists($path),
            'CSV-файл должен быть создан.'
        );

        $content = Storage::disk('local')->get($path);
        $lines = array_values(array_filter(explode("\n", trim($content))));

        $this->assertGreaterThanOrEqual(3, count($lines), 'Файл должен содержать заголовок и две строки данных.');
        $this->assertStringContainsString('ticker', $lines[0], 'Первая строка должна быть заголовком.');
        $this->assertStringContainsString('SBER', $lines[1], 'В данных должна присутствовать строка с тикером SBER.');
    }

    /**
     * Тест: readExistingData корректно возвращает ключи ticker|time.
     */
    public function test_read_existing_data_returns_ticker_time_keys(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::fake('local');

        $path = 'securities/SBER.csv';
        $csv = implode("\n", [
            'ticker,time,open,high,low,close,volume',
            'SBER,2025-01-01 10:00:00,100,110,90,105,1000',
            'SBER,2025-01-02 10:00:00,105,115,95,110,1500',
        ]);

        Storage::disk('local')->put($path, $csv);

        $service = new SecurityCsvService();

        $existing = $service->readExistingData('SBER');

        $this->assertArrayHasKey('SBER|2025-01-01 10:00:00', $existing);
        $this->assertArrayHasKey('SBER|2025-01-02 10:00:00', $existing);
        $this->assertCount(2, $existing);
    }

    /**
     * Тест: getLastDate возвращает null, если данных нет.
     */
    public function test_get_last_date_returns_null_when_no_file_or_no_data(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::fake('local');

        $service = new SecurityCsvService();

        $this->assertNull(
            $service->getLastDate('SBER'),
            'Для отсутствующего файла должна возвращаться null.'
        );

        // Создаём файл только с заголовком
        Storage::disk('local')->put('securities/SBER.csv', "ticker,time,open,high,low,close,volume\n");

        $this->assertNull(
            $service->getLastDate('SBER'),
            'Для файла без данных также должна возвращаться null.'
        );
    }
}


