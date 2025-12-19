<?php

namespace App\Http\Controllers;

use App\Models\Stock;
use App\Services\SecurityCsvService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function index(): View
    {
        try {
            $stocks = Stock::orderBy('ticker')->get();
        } catch (\Throwable $e) {
            \Log::warning('Не удалось загрузить список ценных бумаг из БД: '.$e->getMessage());
            $stocks = collect();
        }

        return view('admin.stocks', [
            'stocks' => $stocks,
        ]);
    }

    public function updateAvailability(Request $request, Stock $stock): RedirectResponse
    {
        $request->validate([
            'is_available' => ['required', 'boolean'],
        ]);

        $stock->is_available = $request->boolean('is_available');
        $stock->save();

        if ($stock->is_available) {
            return redirect()->route('admin.stocks')
                ->with('success', "Тикер {$stock->ticker} включен для пользователей.");
        } else {
            return redirect()->route('admin.stocks')
                ->with('error', "Тикер {$stock->ticker} выключен для пользователей.");
        }
    }

    public function addTicker(Request $request)
    {
        try {
            $request->validate([
                'ticker' => ['required', 'string', 'max:10', 'unique:stocks,ticker'],
            ], [
                'ticker.unique' => 'Ценная бумага с таким тикером уже существует.',
            ]);

            $ticker = strtoupper($request->input('ticker'));

            set_time_limit(600);
            ini_set('max_execution_time', '600');

            $info = $this->fetchStockInfo($ticker);

            if (empty($info['isin'])) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'error' => "Ценная бумага {$ticker} не найдена. Проверьте правильность введенной ценной бумаги.",
                    ], 422);
                }

                return redirect()->route('admin.stocks')
                    ->with('error', "Ценная бумага {$ticker} не найдена. Проверьте правильность введенной ценной бумаги.");
            }

            $stock = Stock::create([
                'ticker' => $ticker,
                'name' => $info['name'] ?? $ticker,
                'isin' => $info['isin'],
                'is_available' => true,
            ]);

            try {
                $csvService = app(SecurityCsvService::class);
                $this->loadInitialData($ticker, $csvService);
            } catch (\Exception $e) {
                \Log::error("Ошибка при загрузке данных для тикера {$ticker}: ".$e->getMessage());
            }

            try {
                \Log::info("Обновление CSV для тикера {$ticker} перед обучением модели...");

                set_time_limit(120);

                Artisan::call('securities:update-csv', [
                    '--ticker' => $ticker,
                ]);

                \Log::info("CSV обновлен для тикера {$ticker}");
            } catch (\Exception $e) {
                \Log::warning("Не удалось обновить CSV для тикера {$ticker} перед обучением: ".$e->getMessage());
            }

            set_time_limit(600);
            ini_set('max_execution_time', '600');

            try {
                $this->runStockNotebook($ticker);
            } catch (\Exception $e) {
                \Log::warning("Не удалось запустить обучение модели для тикера {$ticker}: ".$e->getMessage());
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => "Тикер {$ticker} успешно добавлен. Обучение модели начато.",
                    'ticker' => $ticker,
                ]);
            }

            return redirect()->route('admin.stocks')
                ->with('success', "Тикер {$ticker} успешно добавлен и данные загружены.");

        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $e->errors(),
                ], 422);
            }
            throw $e;
        } catch (\Exception $e) {
            \Log::error('Ошибка при добавлении тикера: '.$e->getMessage());
            \Log::error('Stack trace: '.$e->getTraceAsString());

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Произошла ошибка при добавлении тикера: '.$e->getMessage(),
                ], 500);
            }

            return redirect()->route('admin.stocks')
                ->with('error', 'Произошла ошибка при добавлении тикера: '.$e->getMessage());
        }
    }

    public function checkModelStatus(string $ticker)
    {
        $modelPath = base_path('models/lstm_patterns_'.strtolower($ticker).'.h5');
        $scalerPath = base_path('models/scaler_patterns_'.strtolower($ticker).'.pkl');

        $isReady = file_exists($modelPath) && file_exists($scalerPath);

        return response()->json([
            'ticker' => strtoupper($ticker),
            'is_ready' => $isReady,
            'model_exists' => file_exists($modelPath),
            'scaler_exists' => file_exists($scalerPath),
        ]);
    }

    private function runStockNotebook(string $ticker): void
    {
        $stockScript = base_path('stock.py');

        if (! file_exists($stockScript)) {
            \Log::warning("Скрипт обучения не найден: {$stockScript}");

            return;
        }

        try {
            $pythonCommand = $this->findPythonCommand();

            if (! $pythonCommand) {
                \Log::error('Python с TensorFlow и всеми необходимыми модулями (pandas, numpy, sklearn) не найден в системе');
                \Log::error('Проверьте логи выше для деталей поиска Python');
                \Log::error('Убедитесь, что в Python 3.11 установлены все модули: pip install pandas numpy scikit-learn tensorflow');

                return;
            }

            \Log::info("Используется Python: {$pythonCommand}");

            \Log::info("Запуск обучения модели для тикера {$ticker} через stock.py");

            $modelsDir = base_path('models');
            if (! is_dir($modelsDir)) {
                mkdir($modelsDir, 0755, true);
            }

            $logFile = storage_path('logs/model_training_'.strtolower($ticker).'_'.time().'.log');

            if (PHP_OS_FAMILY === 'Windows') {
                $quotePath = function ($path) {
                    $normalized = str_replace('/', '\\', $path);
                    if (strpos($normalized, '"') === 0 && substr($normalized, -1) === '"') {
                        return $normalized;
                    }

                    return '"'.$normalized.'"';
                };

                if (file_exists($pythonCommand)) {
                    $pythonCmd = $quotePath($pythonCommand);
                } elseif (strpos($pythonCommand, ' ') !== false) {
                    $pythonCmd = $pythonCommand;
                } else {
                    $pythonCmd = $pythonCommand;
                }

                $stockScriptQuoted = $quotePath($stockScript);
                $logFileQuoted = $quotePath($logFile);

                $command = sprintf(
                    'chcp 65001 >nul && start /B "" %s %s %s > %s 2>&1',
                    $pythonCmd,
                    $stockScriptQuoted,
                    escapeshellarg($ticker),
                    $logFileQuoted
                );

                pclose(popen($command, 'r'));
            } else {
                $command = sprintf(
                    'PYTHONIOENCODING=utf-8 %s %s %s > %s 2>&1 &',
                    escapeshellcmd($pythonCommand),
                    escapeshellarg($stockScript),
                    escapeshellarg($ticker),
                    escapeshellarg($logFile)
                );
                exec($command);
            }

            \Log::info("Команда запущена: {$command}. Лог будет в: {$logFile}");

        } catch (\Exception $e) {
            \Log::error("Ошибка при запуске обучения модели для тикера {$ticker}: ".$e->getMessage());
            \Log::error('Stack trace: '.$e->getTraceAsString());
        }
    }

    private function findPythonCommand(): ?string
    {
        $commands = [];

        if (PHP_OS_FAMILY === 'Windows') {
            $localAppData = getenv('LOCALAPPDATA');
            $userName = get_current_user();

            $possiblePython311Paths = [
                $localAppData.'\\Programs\\Python\\Python311\\python.exe',
                'C:\\Users\\'.$userName.'\\AppData\\Local\\Programs\\Python\\Python311\\python.exe',
                'C:\\Python311\\python.exe',
                'C:\\Program Files\\Python311\\python.exe',
            ];

            \Log::info('Поиск Python 3.11 в следующих путях: '.implode(', ', $possiblePython311Paths));

            foreach ($possiblePython311Paths as $pythonPath) {
                if (file_exists($pythonPath)) {
                    \Log::info("Найден Python по пути: {$pythonPath}, проверка модулей...");

                    // Проверяем все необходимые модули
                    $requiredModules = ['tensorflow', 'pandas', 'numpy', 'sklearn'];
                    $missingModules = [];
                    $hasTensorFlow = false;
                    $tfVersion = null;

                    foreach ($requiredModules as $module) {
                        if ($module === 'tensorflow') {
                            $check = shell_exec(escapeshellarg($pythonPath).' -c "import tensorflow; print(tensorflow.__version__)" 2>&1');
                            if ($check && preg_match('/\b\d+\.\d+\.\d+\b/', $check)) {
                                preg_match('/\b(\d+\.\d+\.\d+)\b/', $check, $matches);
                                $tfVersion = $matches[1] ?? 'unknown';
                                $hasTensorFlow = true;
                            } else {
                                $missingModules[] = $module;
                            }
                        } else {
                            // Для sklearn проверяем импорт sklearn, а не scikit-learn
                            $importName = ($module === 'sklearn') ? 'sklearn' : $module;
                            // Используем более надежную проверку - проверяем наличие модуля через попытку импорта с выводом версии или простого атрибута
                            $check = shell_exec(escapeshellarg($pythonPath).' -c "import '.$importName.'; print(\'OK\')" 2>&1');

                            \Log::info("  Проверка модуля {$module} (import {$importName}): ".substr($check ?? 'нет вывода', 0, 200));

                            // Модуль найден, если:
                            // 1. Вывод содержит 'OK' (наш маркер успешного импорта)
                            // 2. НЕТ сообщений об ошибках импорта
                            $hasError = $check && (
                                stripos($check, 'No module named') !== false ||
                                stripos($check, 'ModuleNotFoundError') !== false ||
                                (stripos($check, 'Error') !== false && stripos($check, 'ImportError') !== false)
                            );

                            $hasOK = $check && stripos($check, 'OK') !== false;

                            if ($hasError || (! $check && ! $hasOK)) {
                                $missingModules[] = $module;
                                \Log::warning("  Модуль {$module} не найден в {$pythonPath}. Вывод: ".substr($check ?? 'нет вывода', 0, 200));
                            } else {
                                \Log::info("  ✓ Модуль {$module} найден в {$pythonPath}");
                            }
                        }
                    }

                    if (empty($missingModules) && $hasTensorFlow) {
                        \Log::info("Найден Python 3.11 с TensorFlow {$tfVersion} и всеми модулями по пути: {$pythonPath}");

                        return $pythonPath;
                    } else {
                        if ($hasTensorFlow) {
                            \Log::warning("Python найден с TensorFlow {$tfVersion}, но отсутствуют модули: ".implode(', ', $missingModules));
                        } else {
                            \Log::info('Python найден, но TensorFlow отсутствует');
                        }
                    }
                }
            }

            $pyCommands = [
                ['py', '-3.11'],
                ['py', '-3.10'],
                ['py', '-3.9'],
                ['py', '-3'],
            ];

            foreach ($pyCommands as $cmdParts) {
                $cmdStr = escapeshellarg($cmdParts[0]).' '.escapeshellarg($cmdParts[1]);

                $requiredModules = ['tensorflow', 'pandas', 'numpy', 'sklearn'];
                $missingModules = [];
                $hasTensorFlow = false;
                $tfVersion = null;

                foreach ($requiredModules as $module) {
                    if ($module === 'tensorflow') {
                        $check = shell_exec($cmdStr.' -c "import tensorflow; print(tensorflow.__version__)" 2>&1');
                        if ($check && preg_match('/\b\d+\.\d+\.\d+\b/', $check)) {
                            preg_match('/\b(\d+\.\d+\.\d+)\b/', $check, $matches);
                            $tfVersion = $matches[1] ?? 'unknown';
                            $hasTensorFlow = true;
                        } else {
                            $missingModules[] = $module;
                        }
                    } else {
                        $importName = ($module === 'sklearn') ? 'sklearn' : $module;
                        $check = shell_exec($cmdStr.' -c "import '.$importName.'; print(\'OK\')" 2>&1');

                        \Log::info("  Проверка модуля {$module} через {$cmdStr} (import {$importName}): ".substr($check ?? 'нет вывода', 0, 200));

                        $hasError = $check && (
                            stripos($check, 'No module named') !== false ||
                            stripos($check, 'ModuleNotFoundError') !== false ||
                            (stripos($check, 'Error') !== false && stripos($check, 'ImportError') !== false)
                        );

                        $hasOK = $check && stripos($check, 'OK') !== false;

                        if ($hasError || (! $check && ! $hasOK)) {
                            $missingModules[] = $module;
                            \Log::warning("  Модуль {$module} не найден через {$cmdStr}. Вывод: ".substr($check ?? 'нет вывода', 0, 200));
                        } else {
                            \Log::info("  ✓ Модуль {$module} найден через {$cmdStr}");
                        }
                    }
                }

                if (empty($missingModules) && $hasTensorFlow) {
                    \Log::info("Найден Python с TensorFlow {$tfVersion} и всеми модулями: ".implode(' ', $cmdParts));

                    return implode(' ', $cmdParts);
                }
            }

            // Потом проверяем обычные команды
            $commands = ['python3.11', 'python3', 'python'];
        } else {
            // Для Unix систем
            $commands = ['python3.11', 'python3', 'python'];
        }

        // Ищем Python с TensorFlow в обычных командах
        foreach ($commands as $cmd) {
            $output = [];
            $returnVar = 0;
            exec(escapeshellcmd($cmd).' --version 2>&1', $output, $returnVar);

            if ($returnVar === 0) {
                // Проверяем наличие TensorFlow
                $tfCheck = shell_exec(escapeshellcmd($cmd).' -c "import tensorflow; print(tensorflow.__version__)" 2>&1');
                if ($tfCheck && strpos($tfCheck, 'error') === false &&
                    strpos($tfCheck, 'No module') === false &&
                    strpos($tfCheck, 'not found') === false &&
                    preg_match('/\d+\.\d+\.\d+/', $tfCheck)) {
                    \Log::info("Найден Python с TensorFlow: {$cmd}");

                    return $cmd;
                }
            }
        }

        \Log::error('Не удалось найти Python со всеми необходимыми модулями (tensorflow, pandas, numpy, sklearn)');
        \Log::error('Убедитесь, что Python 3.11 с TensorFlow установлен в одном из стандартных путей');

        return null;
    }

    public function delete(Stock $stock): RedirectResponse
    {
        $ticker = $stock->ticker;
        $stock->delete();

        return redirect()->route('admin.stocks')
            ->with('success', "Тикер {$ticker} успешно удален.");
    }

    private function loadInitialData(string $ticker, SecurityCsvService $csvService): void
    {
        try {
            $from = Carbon::now()->subYears(2);
            $interval = 24;

            $url = sprintf(
                'https://iss.moex.com/iss/engines/stock/markets/shares/boards/TQBR/securities/%s/candles.json',
                $ticker
            );

            $response = Http::withoutVerifying()->timeout(30)->get($url, [
                'interval' => $interval,
                'from' => $from->toDateString(),
            ]);

            if (! $response->successful()) {
                \Log::warning("Ошибка загрузки данных для {$ticker}: HTTP {$response->status()}");

                return;
            }

            $body = $response->json();
            $columns = $body['candles']['columns'] ?? [];
            $data = $body['candles']['data'] ?? [];

            if (empty($data)) {
                \Log::info("Нет данных для тикера {$ticker}");

                return;
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

            // Сохраняем данные в CSV
            $added = $csvService->appendData($ticker, $points);
            \Log::info("Загружено {$added} записей для тикера {$ticker}");

        } catch (\Exception $e) {
            \Log::error("Ошибка при загрузке данных для тикера {$ticker}: ".$e->getMessage());
        }
    }

    private function addPredictionsToCsv(string $ticker, SecurityCsvService $csvService): void
    {
        set_time_limit(300);
        ini_set('max_execution_time', '300');

        try {
            $modelPath = base_path('models/lstm_patterns_'.strtolower($ticker).'.h5');
            $scalerPath = base_path('models/scaler_patterns_'.strtolower($ticker).'.pkl');

            if (! file_exists($modelPath) || ! file_exists($scalerPath)) {
                \Log::info("Модель для {$ticker} не найдена, пропускаем прогнозы");

                return;
            }

            $pythonCommand = $this->findPythonCommand();
            if (! $pythonCommand) {
                \Log::warning("Python с TensorFlow не найден, пропускаем прогнозы для {$ticker}");

                return;
            }

            $predictScript = base_path('predict_future.py');
            if (! file_exists($predictScript)) {
                \Log::warning("Скрипт прогнозирования не найден: {$predictScript}");

                return;
            }

            \Log::info("Генерация прогнозов для {$ticker}...");

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
                \Log::warning("Скрипт прогнозирования не вернул результат для {$ticker}");

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
                \Log::warning("Ошибка парсинга JSON для {$ticker}: ".json_last_error_msg().' Output: '.substr($output, 0, 200));

                return;
            }

            if (isset($result['error'])) {
                \Log::warning("Ошибка при генерации прогнозов для {$ticker}: ".$result['error']);

                return;
            }

            if (! isset($result['predictions']) || empty($result['predictions'])) {
                \Log::warning("Нет прогнозных данных для {$ticker}");

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
            \Log::info("Добавлено {$addedPredictions} прогнозных записей для тикера {$ticker}");

        } catch (\Exception $e) {
            \Log::error("Ошибка при добавлении прогнозов для {$ticker}: ".$e->getMessage());
        }
    }

    private function fetchStockInfo(string $ticker): array
    {
        try {
            $url = sprintf(
                'https://iss.moex.com/iss/engines/stock/markets/shares/boards/TQBR/securities/%s.json',
                $ticker
            );

            $response = \Illuminate\Support\Facades\Http::withoutVerifying()->timeout(10)->get($url, [
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
            return ['name' => $ticker, 'isin' => null];
        }
    }
}
