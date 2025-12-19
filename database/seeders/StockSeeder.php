<?php

namespace Database\Seeders;

use App\Models\Stock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;

class StockSeeder extends Seeder
{
    /**
     * Тикеры для заполнения
     */
    private const TICKERS = ['SBER', 'GAZP', 'VTBR', 'T', 'NVTK'];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::TICKERS as $ticker) {
            // Проверяем, существует ли уже запись
            $stock = Stock::where('ticker', $ticker)->first();

            if ($stock) {
                $this->command->info("Тикер {$ticker} уже существует, пропускаем.");

                continue;
            }

            // Пытаемся получить информацию о тикере из MOEX API
            $info = $this->fetchStockInfo($ticker);

            Stock::create([
                'ticker' => $ticker,
                'name' => $info['name'] ?? $ticker,
                'isin' => $info['isin'] ?? null,
                'is_available' => true,
            ]);

            $this->command->info("Создан тикер: {$ticker}");
        }
    }

    /**
     * Получить информацию о тикере из MOEX API
     */
    private function fetchStockInfo(string $ticker): array
    {
        try {
            $url = sprintf(
                'https://iss.moex.com/iss/engines/stock/markets/shares/boards/TQBR/securities/%s.json',
                $ticker
            );

            $response = Http::withoutVerifying()->timeout(10)->get($url, [
                'iss.meta' => 'off',
                'securities.columns' => 'SECID,SHORTNAME,ISIN',
            ]);

            if (! $response->successful()) {
                return ['name' => $ticker, 'isin' => null];
            }

            $body = $response->json();
            $columns = $body['securities']['columns'] ?? [];
            $data = $body['securities']['data'][0] ?? null;

            if (! $data) {
                return ['name' => $ticker, 'isin' => null];
            }

            $idx = [
                'SECID' => array_search('SECID', $columns, true),
                'SHORTNAME' => array_search('SHORTNAME', $columns, true),
                'ISIN' => array_search('ISIN', $columns, true),
            ];

            return [
                'name' => $data[$idx['SHORTNAME']] ?? $ticker,
                'isin' => $data[$idx['ISIN']] ?? null,
            ];
        } catch (\Exception $e) {
            $this->command->warn("Ошибка загрузки информации о тикере {$ticker}: ".$e->getMessage());

            return ['name' => $ticker, 'isin' => null];
        }
    }
}
