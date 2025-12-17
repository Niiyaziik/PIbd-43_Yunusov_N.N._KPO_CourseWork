<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PredictionService
{
    /**
     * Получить предсказания для тикера через Python скрипт
     */
    public function getPredictions(string $ticker, Collection $data): array
    {
        if ($data->isEmpty()) {
            return [];
        }

        // Путь к Python скрипту на основе stock.ipynb
        $pythonScript = base_path('predict_from_notebook.py');
        
        // Определяем команду Python (пробуем python3, затем python)
        $pythonCommand = $this->findPythonCommand();
        
        // Проверяем наличие Python скрипта
        if (!file_exists($pythonScript)) {
            Log::error("Python скрипт не найден: {$pythonScript}");
            return $this->getFallbackPrediction($ticker, $data);
        }

        try {
            // Выполняем Python скрипт
            $command = escapeshellcmd($pythonCommand) . ' ' . escapeshellarg($pythonScript) . ' ' . escapeshellarg($ticker);
            $output = shell_exec($command . ' 2>&1');
            
            if (empty($output)) {
                Log::error("Python скрипт не вернул результат для тикера: {$ticker}. Команда: {$command}");
                return $this->getFallbackPrediction($ticker, $data);
            }

            $result = json_decode($output, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Ошибка парсинга JSON от Python скрипта: " . json_last_error_msg() . " Output: " . $output);
                return $this->getFallbackPrediction($ticker, $data);
            }

            if (isset($result['error'])) {
                Log::error("Ошибка в Python скрипте: " . $result['error']);
                return $this->getFallbackPrediction($ticker, $data);
            }

            // Возвращаем результат в нужном формате
            $currentPrice = (float)($result['current_price'] ?? 0);
            $nextDayPrice = (float)($result['next_day_price'] ?? ($result['predicted_price'] ?? 0));
            $yearPrice    = (float)($result['year_price'] ?? ($result['predicted_price'] ?? 0));

            return [
                [
                    'ticker' => $result['ticker'] ?? $ticker,
                    'current_price' => $currentPrice,
                    // отдельные поля для прогноза на 1 день и на 252 дня
                    'predicted_price_1d' => $nextDayPrice,
                    'predicted_price_252d' => $yearPrice,
                    // для совместимости можно оставить агрегированное поле
                    'predicted_price' => $yearPrice,
                    'recommendation' => $result['recommendation'] ?? 'Удержание',
                ]
            ];

        } catch (\Exception $e) {
            Log::error("Ошибка выполнения Python скрипта: " . $e->getMessage());
            return $this->getFallbackPrediction($ticker, $data);
        }
    }

    /**
     * Резервный метод предсказания (если Python скрипт не работает)
     */
    private function getFallbackPrediction(string $ticker, Collection $data): array
    {
        $lastPoint = $data->last();
        $currentPrice = $lastPoint['close'];
        
        // Простое предсказание на основе последних изменений
        $prices = $data->pluck('close')->toArray();
        $recentChange = 0;
        
        if (count($prices) >= 2) {
            $recentChange = ($prices[count($prices) - 1] - $prices[count($prices) - 2]) / $prices[count($prices) - 2];
        }
        
        // Простой прогноз на 1 день и на год на основе последнего изменения
        $predictedPrice1d = $currentPrice * (1 + $recentChange);
        $predictedPrice252d = $currentPrice * (1 + $recentChange * 0.5);

        $recommendation = $recentChange > 0 ? 'Покупать' : 'Не покупать';
        
        return [
            [
                'ticker' => $ticker,
                'current_price' => $currentPrice,
                'predicted_price_1d' => $predictedPrice1d,
                'predicted_price_252d' => $predictedPrice252d,
                'predicted_price' => $predictedPrice252d,
                'recommendation' => $recommendation,
            ]
        ];
    }

    /**
     * Найти команду Python
     */
    private function findPythonCommand(): string
    {
        // Пробуем python3
        $python3 = shell_exec('which python3 2>&1');
        if (!empty($python3) && strpos($python3, 'not found') === false) {
            return 'python3';
        }
        
        // Пробуем python
        $python = shell_exec('which python 2>&1');
        if (!empty($python) && strpos($python, 'not found') === false) {
            return 'python';
        }
        
        // Для Windows
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return 'python';
        }
        
        return 'python3';
    }
}

