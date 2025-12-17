<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Графики акций MOEX</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans bg-white text-gray-900">
    <div class="max-w-6xl mx-auto px-4 py-6" id="securities-app"
         data-api-base="{{ url('/api/securities') }}"
         data-default-ticker="{{ $defaultTicker }}"
         data-default-interval="{{ $defaultInterval }}"
         data-tickers='@json($tickers)'
         data-intervals='@json($intervals)'
         data-details-base="{{ url('/securities') }}">
        <header class="mb-6">
            <div class="flex justify-between items-center mb-2">
                <h1 class="text-2xl font-semibold">Графики российских акций (MOEX)</h1>
                <div class="flex items-center gap-4">
                    <span class="text-sm text-gray-600">
                        @if(Auth::user()->login === 'admin')
                            Администратор
                        @else
                            {{ Auth::user()->first_name }} {{ Auth::user()->last_name }}
                        @endif
                    </span>
                    @if(Auth::user()->login === 'admin')
                        <a href="{{ route('admin.stocks') }}" class="px-4 py-2 text-sm bg-green-600 text-white rounded hover:bg-green-700">
                            Управление ценными бумагами
                        </a>
                    @endif
                    <button type="button" id="logout-btn" class="px-4 py-2 text-sm bg-red-600 text-white rounded hover:bg-red-700">
                        Выйти
                    </button>
                    
                    <!-- Модальное окно подтверждения выхода -->
                    <div id="logout-modal" class="hidden fixed inset-0  bg-opacity-30 overflow-y-auto h-full w-full z-50">
                        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                            <div class="mt-3">
                                <h3 class="text-lg font-medium text-gray-900 text-center mb-2">Подтверждение выхода</h3>
                                <div class="mt-2 px-4 py-3">
                                    <p class="text-sm text-gray-500 text-center">
                                        Вы уверены, что хотите выйти из системы?
                                    </p>
                                </div>
                                <div class="flex gap-3 px-4 py-3">
                                    <form method="POST" action="{{ route('logout') }}" class="flex-1">
                                        @csrf
                                        <button type="submit" class="w-full px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500">
                                            Да, выйти
                                        </button>
                                    </form>
                                    <button type="button" id="cancel-logout" class="flex-1 px-4 py-2 bg-gray-300 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500">
                                        Отмена
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <script>
                        document.getElementById('logout-btn').addEventListener('click', function() {
                            document.getElementById('logout-modal').classList.remove('hidden');
                        });
                        
                        document.getElementById('cancel-logout').addEventListener('click', function() {
                            document.getElementById('logout-modal').classList.add('hidden');
                        });
                        
                        // Закрытие при клике вне модального окна
                        document.getElementById('logout-modal').addEventListener('click', function(e) {
                            if (e.target === this) {
                                this.classList.add('hidden');
                            }
                        });
                    </script>
                </div>
            </div>
        </header>

        <section class="bg-white shadow rounded-lg p-4 mb-6">
            <div class="flex flex-wrap gap-3 items-end">
                <div>
                    <label class="block text-xs text-gray-600 mb-1" for="ticker-select">Ценная бумага</label>
                    <select id="ticker-select" class="border rounded px-3 py-2 text-sm"></select>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Интервал</label>
                    <div id="interval-buttons" class="flex flex-wrap gap-2"></div>
                    <select id="interval-select" class="hidden"></select>
                </div>
            </div>
        </section>

        <section class="bg-white shadow rounded-lg p-4">
            <div class="flex items-center gap-3 mb-3">
                <h2 class="font-semibold text-sm">График</h2>
                <button id="switch-to-line" class="px-3 py-1 text-sm border rounded bg-blue-50 text-blue-700 hover:bg-blue-100">Линейный</button>
                <button id="switch-to-candle" class="px-3 py-1 text-sm border rounded bg-amber-50 text-amber-700 hover:bg-amber-100">Свечной</button>
                <div id="current-price" class="text-sm text-gray-700 ml-auto"></div>
            </div>
            <canvas id="main-chart" height="180"></canvas>
        </section>

        <section class="mt-4 flex justify-end items-center gap-4">
            <a id="security-details-link"
               href="{{ route('securities.show', ['ticker' => $defaultTicker]) }}"
               class="text-sm text-blue-600 hover:text-blue-800">
                Подробнее о ценной бумаге
            </a>
            <a id="download-excel-link"
               href="{{ route('securities.export', ['ticker' => $defaultTicker, 'format' => 'excel']) }}"
               class="px-4 py-2 text-sm bg-green-600 text-white rounded hover:bg-green-700 flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                Скачать Excel
            </a>
            <a id="download-pdf-link"
               href="{{ route('securities.export', ['ticker' => $defaultTicker, 'format' => 'pdf']) }}"
               class="px-4 py-2 text-sm bg-red-600 text-white rounded hover:bg-red-700 flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                Скачать PDF
            </a>
        </section>
    </div>
</body>
</html>

