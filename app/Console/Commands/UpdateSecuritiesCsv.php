<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\SecurityCsvService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class UpdateSecuritiesCsv extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'securities:update-csv 
                            {--ticker= : Обновить только указанный тикер}
                            {--interval=24 : Интервал для загрузки (24 = день)}
                            {--all : Загрузить все данные с начала}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Обновить CSV файлы с данными по акциям MOEX';

    public function handle(SecurityCsvService $csvService): int
    {
        // Если указан конкретный тикер через опцию --ticker, обновляем только его
        if ($this->option('ticker')) {
            $tickers = [strtoupper($this->option('ticker'))];
        } else {
            // Иначе берём все доступные тикеры из таблицы stocks (is_available = true)
            $tickers = Stock::where('is_available', true)
                ->orderBy('ticker')
                ->pluck('ticker')
                ->toArray();
        }

        $interval = (int) $this->option('interval');
        $loadAll = $this->option('all');

        if (empty($tickers)) {
            $this->warn('Нет доступных тикеров для обновления.');
            return Command::SUCCESS;
        }

        $this->info('Начинаю обновление CSV файлов...');

        foreach ($tickers as $ticker) {

            $this->line("Обрабатываю тикер: {$ticker}");

            try {
                // Определяем дату начала загрузки
                $from = $loadAll
                    ? Carbon::now()->subYears(2) // Загружаем за последние 2 года
                    : ($csvService->getLastDate($ticker) ?? Carbon::now()->subDays(30));

                if (!$loadAll && $csvService->getLastDate($ticker)) {
                    // Начинаем со следующего дня после последней записи
                    $from = $from->addDay();
                }

                // Загружаем данные с MOEX
                $url = sprintf(
                    'https://iss.moex.com/iss/engines/stock/markets/shares/boards/TQBR/securities/%s/candles.json',
                    $ticker
                );

                $response = Http::withoutVerifying()->timeout(30)->get($url, [
                    'interval' => $interval,
                    'from' => $from->toDateString(),
                ]);

                if (!$response->successful()) {
                    $this->error("Ошибка загрузки данных для {$ticker}: HTTP {$response->status()}");
                    continue;
                }

                $body = $response->json();
                $columns = $body['candles']['columns'] ?? [];
                $data = $body['candles']['data'] ?? [];

                if (empty($data)) {
                    $this->warn("Нет новых данных для {$ticker}");
                    continue;
                }

                $index = [
                    'open' => array_search('open', $columns, true),
                    'close' => array_search('close', $columns, true),
                    'high' => array_search('high', $columns, true),
                    'low' => array_search('low', $columns, true),
                    'volume' => array_search('volume', $columns, true),
                    'begin' => array_search('begin', $columns, true),
                ];

                $points = [];
                foreach ($data as $row) {
                    $points[] = [
                        'time' => $row[$index['begin']] ?? null,
                        'open' => (float) ($row[$index['open']] ?? 0),
                        'high' => (float) ($row[$index['high']] ?? 0),
                        'low' => (float) ($row[$index['low']] ?? 0),
                        'close' => (float) ($row[$index['close']] ?? 0),
                        'volume' => (int) ($row[$index['volume']] ?? 0),
                    ];
                }

                // Добавляем в CSV только новые записи
                $added = $csvService->appendData($ticker, $points);
                $this->info("  ✓ Добавлено новых записей: {$added}");

            } catch (\Exception $e) {
                $this->error("Ошибка при обработке {$ticker}: " . $e->getMessage());
                continue;
            }
        }

        $this->info("Обновление завершено!");
        return Command::SUCCESS;
    }
}

