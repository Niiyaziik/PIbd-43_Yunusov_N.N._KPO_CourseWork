<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class SecurityCsvService
{
    private const CSV_DIR = 'securities';
    private const CSV_HEADERS = ['ticker', 'time', 'open', 'high', 'low', 'close', 'volume'];

    /**
     * Получить путь к CSV файлу для тикера
     */
    private function getCsvPath(string $ticker): string
    {
        return self::CSV_DIR . '/' . strtoupper($ticker) . '.csv';
    }

    /**
     * Прочитать существующие данные из CSV
     * Возвращает массив, где ключ - это строка "ticker|time" для проверки дубликатов
     */
    public function readExistingData(string $ticker): array
    {
        $path = $this->getCsvPath($ticker);
        
        if (!Storage::exists($path)) {
            return [];
        }

        $existing = [];
        $lines = explode("\n", Storage::get($path));
        
        // Определяем, есть ли заголовок
        $startIndex = 0;
        if (count($lines) > 0) {
            $firstLine = trim($lines[0]);
            if (stripos($firstLine, 'ticker') !== false || stripos($firstLine, 'time') !== false) {
                $startIndex = 1;
            }
        }
        
        for ($i = $startIndex; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) {
                continue;
            }
            
            $row = str_getcsv($line);
            if (count($row) >= 2) {
                // Пропускаем заголовок если он попался
                if (strtolower($row[0]) === 'ticker') {
                    continue;
                }
                $key = $row[0] . '|' . $row[1]; // ticker|time
                $existing[$key] = true;
            }
        }

        return $existing;
    }

    /**
     * Добавить новые данные в CSV (только те, которых ещё нет)
     */
    public function appendData(string $ticker, array $points): int
    {
        $path = $this->getCsvPath($ticker);
        $existing = $this->readExistingData($ticker);
        $newCount = 0;
        $newLines = [];

        foreach ($points as $point) {
            $key = strtoupper($ticker) . '|' . $point['time'];
            
            // Пропускаем дубликаты
            if (isset($existing[$key])) {
                continue;
            }

            $newLines[] = [
                strtoupper($ticker),
                $point['time'],
                $point['open'],
                $point['high'],
                $point['low'],
                $point['close'],
                $point['volume'],
            ];
            $newCount++;
        }

        if ($newCount === 0) {
            return 0;
        }

        // Если файл не существует, создаём с заголовком
        $isNewFile = !Storage::exists($path);

        // Убеждаемся, что директория для файла существует (актуально и для fake-дисков в тестах)
        $fullPath = Storage::path($path);
        $directory = \dirname($fullPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $handle = fopen($fullPath, 'a');
        if ($handle === false) {
            throw new \RuntimeException("Не удалось открыть файл: {$path}");
        }

        if ($isNewFile) {
            fputcsv($handle, self::CSV_HEADERS);
        }

        foreach ($newLines as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $newCount;
    }

    /**
     * Получить последнюю дату в CSV для тикера
     */
    public function getLastDate(string $ticker): ?Carbon
    {
        $path = $this->getCsvPath($ticker);
        
        if (!Storage::exists($path)) {
            return null;
        }

        $lines = explode("\n", Storage::get($path));
        if (count($lines) < 2) {
            return null;
        }

        // Берём последнюю непустую строку
        for ($i = count($lines) - 1; $i >= 1; $i--) {
            $line = trim($lines[$i]);
            if (empty($line)) {
                continue;
            }
            
            $row = str_getcsv($line);
            if (count($row) >= 2) {
                try {
                    return Carbon::parse($row[1]);
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Получить все данные из CSV для тикера
     */
    public function getAllData(string $ticker): Collection
    {
        $path = $this->getCsvPath($ticker);
        
        \Log::info("Попытка загрузить CSV: {$path}");
        
        if (!Storage::exists($path)) {
            \Log::warning("CSV файл не найден: {$path}");
            return collect([]);
        }

        $content = Storage::get($path);
        \Log::info("Размер файла: " . strlen($content) . " байт, строк: " . substr_count($content, "\n"));
        
        $lines = explode("\n", $content);
        $data = [];

        // Начинаем с первой строки, но пропускаем заголовок если он есть
        $startIndex = 0;
        if (count($lines) > 0) {
            $firstLine = trim($lines[0]);
            // Проверяем, является ли первая строка заголовком
            if (stripos($firstLine, 'ticker') !== false || stripos($firstLine, 'time') !== false) {
                $startIndex = 1;
            }
        }

        for ($i = $startIndex; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) {
                continue;
            }
            
            $row = str_getcsv($line);
            if (count($row) >= 7) {
                // Проверяем, что это не заголовок (первое поле должно быть тикером, не "ticker")
                if (strtolower($row[0]) === 'ticker') {
                    continue;
                }
                
                // Убираем кавычки из времени, если они есть
                $time = trim($row[1], '"\'');
                
                $data[] = [
                    'ticker' => $row[0],
                    'time' => $time,
                    'open' => (float) $row[2],
                    'high' => (float) $row[3],
                    'low' => (float) $row[4],
                    'close' => (float) $row[5],
                    'volume' => (int) $row[6],
                ];
            } else {
                // Логируем строки, которые не прошли проверку
                if (count($data) < 3) {
                    \Log::info("Строка не обработана (колонок: " . count($row) . "): " . substr($line, 0, 100));
                }
            }
        }
        
        \Log::info("Обработано строк данных: " . count($data));

        // Сортируем по дате (от старых к новым)
        usort($data, function ($a, $b) {
            return strcmp($a['time'], $b['time']);
        });

        return collect($data);
    }
}

