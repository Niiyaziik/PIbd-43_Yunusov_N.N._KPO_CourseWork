<?php

namespace Tests\Unit;

use App\Services\SecurityCsvService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityCsvServiceTest extends TestCase
{

    public function test_get_last_date_returns_null_when_no_file_or_no_data(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::fake('local');

        $service = new SecurityCsvService;

        $this->assertNull(
            $service->getLastDate('SBER'),
            'Для отсутствующего файла должна возвращаться null.'
        );

        Storage::disk('local')->put('securities/SBER.csv', "ticker,time,open,high,low,close,volume\n");

        $this->assertNull(
            $service->getLastDate('SBER'),
            'Для файла без данных также должна возвращаться null.'
        );
    }
}
