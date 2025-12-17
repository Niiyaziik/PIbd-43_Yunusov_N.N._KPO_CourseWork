<?php

namespace Tests\Unit;

use App\Services\PredictionService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PredictionServiceTest extends TestCase
{
    /**
     * Тест: для пустой коллекции данных возвращается пустой массив.
     */
    public function test_get_predictions_returns_empty_array_for_empty_collection(): void
    {
        $service = new PredictionService();

        $result = $service->getPredictions('SBER', collect());

        $this->assertSame([], $result);
    }

    /**
     * Тест: резервный метод предсказания возвращает ожидаемые значения.
     *
     * Проверяем именно математику на последних двух точках.
     */
    public function test_fallback_prediction_uses_last_two_prices(): void
    {
        $service = new PredictionService();

        $data = new Collection([
            ['close' => 100.0],
            ['close' => 110.0],
        ]);

        $ref = new \ReflectionClass(PredictionService::class);
        $method = $ref->getMethod('getFallbackPrediction');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'SBER', $data);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);

        $prediction = $result[0];

        $this->assertSame('SBER', $prediction['ticker']);
        $this->assertEquals(110.0, $prediction['current_price']);

        // recentChange = (110 - 100) / 100 = 0.1
        // predicted_price_1d = 110 * (1 + 0.1) = 121
        // predicted_price_252d = 110 * (1 + 0.1 * 0.5) = 115.5
        $this->assertEqualsWithDelta(121.0, $prediction['predicted_price_1d'], 0.0001);
        $this->assertEqualsWithDelta(115.5, $prediction['predicted_price_252d'], 0.0001);
    }
}


