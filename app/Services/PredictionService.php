<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PredictionService
{
    public function getPredictions(string $ticker, Collection $data): array
    {
        if ($data->isEmpty()) {
            return [];
        }

        $pythonScript = base_path('predict_future.py');

        $pythonService = app(PythonCommandService::class);
        $pythonCommand = $pythonService->findPythonCommandWithTensorFlow();

        if (! $pythonCommand) {
            Log::warning("Python с TensorFlow не найден, используется fallback прогноз для тикера: {$ticker}");

            return $this->getFallbackPrediction($ticker, $data);
        }

        if (! file_exists($pythonScript)) {
            Log::error("Python скрипт не найден: {$pythonScript}");

            return $this->getFallbackPrediction($ticker, $data);
        }

        try {
            $command = $pythonService->buildPythonCommand(
                $pythonCommand,
                $pythonScript,
                [$ticker, '252', 'snapshot'],
                true
            );

            $output = shell_exec($command);

            if (empty($output)) {
                Log::error("Python скрипт не вернул результат для тикера: {$ticker}. Команда: {$command}");

                return $this->getFallbackPrediction($ticker, $data);
            }

            $jsonStart = strpos($output, '{');
            if ($jsonStart !== false) {
                $jsonOutput = substr($output, $jsonStart);
                $result = json_decode($jsonOutput, true);
            } else {
                $result = json_decode($output, true);
            }

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Ошибка парсинга JSON от Python скрипта: '.json_last_error_msg().' Output: '.substr($output, 0, 500));

                return $this->getFallbackPrediction($ticker, $data);
            }

            if (isset($result['error'])) {
                Log::error('Ошибка в Python скрипте: '.$result['error']);

                return $this->getFallbackPrediction($ticker, $data);
            }

            $currentPrice = (float) ($result['current_price'] ?? 0);
            $predictedPrice252d = (float) ($result['predicted_price_252d'] ?? 0);

            $predictedPrice1d = $currentPrice;
            if (! empty($result['predictions']) && is_array($result['predictions'])) {
                $firstPrediction = reset($result['predictions']);
                $predictedPrice1d = (float) ($firstPrediction['close'] ?? $firstPrediction['predicted_price'] ?? $currentPrice);
            } elseif (isset($result['first_prediction']) && is_numeric($result['first_prediction'])) {
                $predictedPrice1d = (float) $result['first_prediction'];
            }

            if ($predictedPrice252d == 0 && ! empty($result['predictions']) && is_array($result['predictions'])) {
                $lastPrediction = end($result['predictions']);
                $predictedPrice252d = (float) ($lastPrediction['close'] ?? $predictedPrice252d);
            }

            $change = $predictedPrice252d - $currentPrice;
            $changePercent = $currentPrice > 0 ? ($change / $currentPrice) * 100 : 0;
            if ($changePercent > 15) {
                $recommendation = 'Покупать';
            } elseif ($changePercent >= 0) {
                $recommendation = 'Удержание';
            } else {
                $recommendation = 'Не покупать';
            }

            $dataSource = isset($result['data_source']) ? $result['data_source'] : 'unknown';
            Log::info("Прогнозы для {$ticker}: текущая={$currentPrice}, 1д={$predictedPrice1d}, 252д={$predictedPrice252d}, источник={$dataSource}");

            return [
                [
                    'ticker' => $result['ticker'] ?? $ticker,
                    'current_price' => $currentPrice,
                    'predicted_price_1d' => $predictedPrice1d,
                    'predicted_price_252d' => $predictedPrice252d,
                    'predicted_price' => $predictedPrice252d,
                    'recommendation' => $recommendation,
                    'model_accuracy' => isset($result['model_accuracy']) ? (float) $result['model_accuracy'] : null,
                    'data_source' => $result['data_source'] ?? null,
                    'used_snapshot' => $result['used_snapshot'] ?? false,
                ],
            ];

        } catch (\Exception $e) {
            Log::error('Ошибка выполнения Python скрипта: '.$e->getMessage());

            return $this->getFallbackPrediction($ticker, $data);
        }
    }

    private function getFallbackPrediction(string $ticker, Collection $data): array
    {
        $lastPoint = $data->last();
        $currentPrice = $lastPoint['close'];

        $prices = $data->pluck('close')->toArray();
        $recentChange = 0;

        if (count($prices) >= 2) {
            $recentChange = ($prices[count($prices) - 1] - $prices[count($prices) - 2]) / $prices[count($prices) - 2];
        }

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
            ],
        ];
    }
}
