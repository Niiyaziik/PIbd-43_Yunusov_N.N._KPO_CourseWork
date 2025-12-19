<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class SecurityCsvService
{
    private const CSV_DIR = 'securities';

    private const CSV_HEADERS = ['ticker', 'time', 'open', 'high', 'low', 'close', 'volume'];

    private function getCsvPath(string $ticker): string
    {
        return self::CSV_DIR.'/'.strtoupper($ticker).'.csv';
    }

    public function readExistingData(string $ticker): array
    {
        $path = $this->getCsvPath($ticker);

        if (! Storage::exists($path)) {
            return [];
        }

        $existing = [];
        $lines = explode("\n", Storage::get($path));

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
                if (strtolower($row[0]) === 'ticker') {
                    continue;
                }
                $key = $row[0].'|'.$row[1];
                $existing[$key] = true;
            }
        }

        return $existing;
    }

    public function appendData(string $ticker, array $points): int
    {
        $path = $this->getCsvPath($ticker);
        $existing = $this->readExistingData($ticker);
        $newCount = 0;
        $newLines = [];

        foreach ($points as $point) {
            $key = strtoupper($ticker).'|'.$point['time'];

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

        $isNewFile = ! Storage::exists($path);

        $fullPath = Storage::path($path);
        $directory = \dirname($fullPath);
        if (! is_dir($directory)) {
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

    public function getLastDate(string $ticker): ?Carbon
    {
        $path = $this->getCsvPath($ticker);

        if (! Storage::exists($path)) {
            return null;
        }

        $lines = explode("\n", Storage::get($path));
        if (count($lines) < 2) {
            return null;
        }

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

    public function getAllData(string $ticker): Collection
    {
        $path = $this->getCsvPath($ticker);

        \Log::info("Попытка загрузить CSV: {$path}");

        if (! Storage::exists($path)) {
            \Log::warning("CSV файл не найден: {$path}");

            return collect([]);
        }

        $content = Storage::get($path);
        \Log::info('Размер файла: '.strlen($content).' байт, строк: '.substr_count($content, "\n"));

        $lines = explode("\n", $content);
        $data = [];

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
            if (count($row) >= 7) {
                if (strtolower($row[0]) === 'ticker') {
                    continue;
                }

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
                if (count($data) < 3) {
                    \Log::info('Строка не обработана (колонок: '.count($row).'): '.substr($line, 0, 100));
                }
            }
        }

        \Log::info('Обработано строк данных: '.count($data));

        usort($data, function ($a, $b) {
            return strcmp($a['time'], $b['time']);
        });

        return collect($data);
    }
}
