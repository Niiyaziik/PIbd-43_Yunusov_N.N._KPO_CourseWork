<?php

namespace Tests\Unit;

use App\Services\PredictionService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PredictionServiceTest extends TestCase
{
    public function test_get_predictions_returns_empty_array_for_empty_collection(): void
    {
        $service = new PredictionService;

        $result = $service->getPredictions('SBER', collect());

        $this->assertSame([], $result);
    }
}
