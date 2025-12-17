<?php

namespace App\Http\Controllers;

use App\Models\Stock;
use App\Services\SecurityCsvService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;

class AdminController extends Controller
{
    /**
     * Показать страницу управления тикерами
     */
    public function index(): View
    {
        try {
            $stocks = Stock::orderBy('ticker')->get();
        } catch (\Throwable $e) {
            \Log::warning('Не удалось загрузить список ценных бумаг из БД: '.$e->getMessage());
            // В тестовой среде или при отсутствии драйвера БД возвращаем пустую коллекцию,
            // чтобы страница админки продолжала работать.
            $stocks = collect();
        }
        
        return view('admin.stocks', [
            'stocks' => $stocks,
        ]);
    }

    /**
     * Обновить доступность тикера
     */
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

    /**
     * Добавить новый тикер
     */
    public function addTicker(Request $request): RedirectResponse
    {
        $request->validate([
            'ticker' => ['required', 'string', 'max:10', 'unique:stocks,ticker'],
        ], [
            'ticker.unique' => 'Ценная бумага с таким тикером уже существует.',
        ]);

        $ticker = strtoupper($request->input('ticker'));
        
        // Пытаемся получить информацию о тикере из MOEX API
        $info = $this->fetchStockInfo($ticker);
        
        // Проверяем наличие ISIN - если его нет, значит ценной бумаги не существует
        if (empty($info['isin'])) {
            return redirect()->route('admin.stocks')
                ->with('error', "Ценная бумага {$ticker} не найдена. Проверьте правильность введенной ценной бумаги.");
        }
        
        $stock = Stock::create([
            'ticker' => $ticker,
            'name' => $info['name'] ?? $ticker,
            'isin' => $info['isin'],
            'is_available' => true,
        ]);

        // Автоматически загружаем данные в CSV файл
        try {
            $csvService = app(SecurityCsvService::class);
            $this->loadInitialData($ticker, $csvService);
        } catch (\Exception $e) {
            \Log::error("Ошибка при загрузке данных для тикера {$ticker}: " . $e->getMessage());
            // Не прерываем процесс, просто логируем ошибку
        }

        return redirect()->route('admin.stocks')
            ->with('success', "Тикер {$ticker} успешно добавлен и данные загружены.");
    }

    /**
     * Удалить тикер
     */
    public function delete(Stock $stock): RedirectResponse
    {
        $ticker = $stock->ticker;
        $stock->delete();

        return redirect()->route('admin.stocks')
            ->with('success', "Тикер {$ticker} успешно удален.");
    }

    /**
     * Загрузить начальные данные для нового тикера
     */
    private function loadInitialData(string $ticker, SecurityCsvService $csvService): void
    {
        try {
            // Загружаем данные за последние 2 года
            $from = Carbon::now()->subYears(2);
            $interval = 24; // Дневной интервал

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
            \Log::error("Ошибка при загрузке данных для тикера {$ticker}: " . $e->getMessage());
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

            $response = \Illuminate\Support\Facades\Http::withoutVerifying()->timeout(10)->get($url, [
                'iss.meta' => 'off',
                'securities.columns' => 'SECID,SHORTNAME,ISIN',
            ]);

            if (!$response->successful()) {
                return ['name' => $ticker, 'isin' => null];
            }

            $body = $response->json();
            $columns = $body['securities']['columns'] ?? [];
            $data = $body['securities']['data'][0] ?? null;

            if (!$data) {
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

