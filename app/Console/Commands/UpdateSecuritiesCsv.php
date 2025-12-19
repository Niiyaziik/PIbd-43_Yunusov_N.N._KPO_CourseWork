<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\SecurityCsvService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class UpdateSecuritiesCsv extends Command
{
    protected $signature = 'securities:update-csv 
                            {--ticker= : Обновить только указанный тикер}
                            {--interval=24 : Интервал для загрузки (24 = день)}
                            {--all : Загрузить все данные с начала}';

    protected $description = 'Обновить CSV файлы с данными по акциям MOEX';

    public function handle(SecurityCsvService $csvService): int
    {
        set_time_limit(300);
        ini_set('max_execution_time', 300);

        if ($this->option('ticker')) {
            $tickers = [strtoupper($this->option('ticker'))];
        } else {
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

                if (! $loadAll && $csvService->getLastDate($ticker)) {
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

                if (! $response->successful()) {
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

                $added = $csvService->appendData($ticker, $points);
                $this->info("  ✓ Добавлено новых записей: {$added}");

                $this->addPredictionsToCsv($ticker, $csvService);

            } catch (\Exception $e) {
                $this->error("Ошибка при обработке {$ticker}: ".$e->getMessage());

                continue;
            }
        }

        $this->info('Обновление завершено!');

        return Command::SUCCESS;
    }

    private function addPredictionsToCsv(string $ticker, SecurityCsvService $csvService): void
    {
        try {
            $modelPath = base_path('models/lstm_patterns_'.strtolower($ticker).'.h5');
            $scalerPath = base_path('models/scaler_patterns_'.strtolower($ticker).'.pkl');

            if (! file_exists($modelPath) || ! file_exists($scalerPath)) {
                $this->warn("  ⚠ Модель для {$ticker} не найдена, пропускаем прогнозы");

                return;
            }

            $pythonCommand = $this->findPythonCommand();
            if (! $pythonCommand) {
                $this->warn('  ⚠ Python с TensorFlow не найден, пропускаем прогнозы');

                return;
            }

            $predictScript = base_path('predict_future.py');
            if (! file_exists($predictScript)) {
                $this->warn("  ⚠ Скрипт прогнозирования не найден: {$predictScript}");

                return;
            }

            $this->line("  Генерация прогнозов для {$ticker}...");

            if (PHP_OS_FAMILY === 'Windows') {
                $quotePath = function ($path) {
                    $normalized = str_replace('/', '\\', $path);
                    if (strpos($normalized, '"') === 0 && substr($normalized, -1) === '"') {
                        return $normalized;
                    }

                    return '"'.$normalized.'"';
                };

                $pythonCmd = file_exists($pythonCommand)
                    ? $quotePath($pythonCommand)
                    : $pythonCommand;
                $scriptQuoted = $quotePath($predictScript);

                $command = sprintf(
                    '%s %s %s 252',
                    $pythonCmd,
                    $scriptQuoted,
                    escapeshellarg($ticker)
                );
            } else {
                $command = sprintf(
                    'PYTHONIOENCODING=utf-8 %s %s %s 252',
                    escapeshellcmd($pythonCommand),
                    escapeshellarg($predictScript),
                    escapeshellarg($ticker)
                );
            }

            if (PHP_OS_FAMILY === 'Windows') {
                $output = shell_exec($command.' 2>nul');
            } else {
                $output = shell_exec($command.' 2>/dev/null');
            }

            if (empty($output)) {
                $this->warn('  ⚠ Скрипт прогнозирования не вернул результат');

                return;
            }

            $jsonStart = strpos($output, '{');
            if ($jsonStart !== false) {
                $jsonOutput = substr($output, $jsonStart);
                $result = json_decode($jsonOutput, true);
            } else {
                $result = json_decode($output, true);
            }

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->warn('  ⚠ Ошибка парсинга JSON: '.json_last_error_msg());

                return;
            }

            if (isset($result['error'])) {
                $this->warn('  ⚠ Ошибка при генерации прогнозов: '.$result['error']);

                return;
            }

            if (isset($result['used_snapshot'])) {
                if ($result['used_snapshot']) {
                    $this->info('  ✓ Использован снимок данных от: '.($result['snapshot_timestamp'] ?? 'unknown'));
                } else {
                    $this->warn('  ⚠ Использован CSV (снимок не найден или отключен)');
                }
            }

            if (! isset($result['predictions']) || empty($result['predictions'])) {
                $this->warn('  ⚠ Нет прогнозных данных');

                return;
            }

            $predictionPoints = [];
            foreach ($result['predictions'] as $pred) {
                $predictionPoints[] = [
                    'time' => $pred['time'],
                    'open' => (float) $pred['open'],
                    'high' => (float) $pred['high'],
                    'low' => (float) $pred['low'],
                    'close' => (float) $pred['close'],
                    'volume' => (int) ($pred['volume'] ?? 0),
                ];
            }

            $addedPredictions = $csvService->appendData($ticker, $predictionPoints);
            $this->info("  ✓ Добавлено прогнозных записей: {$addedPredictions}");

        } catch (\Exception $e) {
            $this->warn('  ⚠ Ошибка при добавлении прогнозов: '.$e->getMessage());
        }
    }

    private function findPythonCommand(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $localAppData = getenv('LOCALAPPDATA');
            $userName = get_current_user();

            $possiblePython311Paths = [
                $localAppData.'\\Programs\\Python\\Python311\\python.exe',
                'C:\\Users\\'.$userName.'\\AppData\\Local\\Programs\\Python\\Python311\\python.exe',
                'C:\\Python311\\python.exe',
                'C:\\Program Files\\Python311\\python.exe',
            ];

            foreach ($possiblePython311Paths as $pythonPath) {
                if (file_exists($pythonPath)) {
                    $tfCheck = shell_exec(escapeshellarg($pythonPath).' -c "import tensorflow; print(tensorflow.__version__)" 2>&1');
                    if ($tfCheck && preg_match('/\b\d+\.\d+\.\d+\b/', $tfCheck)) {
                        return $pythonPath;
                    }
                }
            }

            $pyCommands = [['py', '-3.11'], ['py', '-3.10'], ['py', '-3']];
            foreach ($pyCommands as $cmdParts) {
                $cmdStr = escapeshellarg($cmdParts[0]).' '.escapeshellarg($cmdParts[1]);
                $tfCheck = shell_exec($cmdStr.' -c "import tensorflow; print(tensorflow.__version__)" 2>&1');
                if ($tfCheck && preg_match('/\b\d+\.\d+\.\d+\b/', $tfCheck)) {
                    return implode(' ', $cmdParts);
                }
            }
        }

        $commands = ['python3.11', 'python3', 'python'];
        foreach ($commands as $cmd) {
            $output = [];
            $returnVar = 0;
            exec(escapeshellcmd($cmd).' --version 2>&1', $output, $returnVar);

            if ($returnVar === 0) {
                $tfCheck = shell_exec(escapeshellcmd($cmd).' -c "import tensorflow; print(tensorflow.__version__)" 2>&1');
                if ($tfCheck && preg_match('/\d+\.\d+\.\d+/', $tfCheck)) {
                    return $cmd;
                }
            }
        }

        return null;
    }
}
