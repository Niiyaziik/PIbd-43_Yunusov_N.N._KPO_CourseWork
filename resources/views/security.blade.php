<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $info['ticker'] ?? 'Ценная бумага' }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="font-sans bg-white text-gray-900">
    <div class="max-w-3xl mx-auto px-4 py-6">
        <header class="mb-6">
            <div class="flex justify-between items-center mb-2">
                <div class="flex items-center gap-3">
                    <a href="{{ route('securities.index') }}" class="text-gray-600 hover:text-gray-900 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                    </a>
                    <h1 class="text-2xl font-semibold">Карточка ценной бумаги</h1>
                </div>
                <div class="flex items-center gap-4">
                    <span class="text-sm text-gray-600">
                        @if(Auth::user()->login === 'admin')
                            Администратор
                        @else
                            {{ Auth::user()->first_name }} {{ Auth::user()->last_name }}
                        @endif
                    </span>
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

        <section class="bg-white shadow rounded-lg p-6 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-xs text-gray-500">Ценная бумага</div>
                    <div class="text-xl font-semibold">{{ $info['ticker'] ?? '—' }}</div>
                </div>
                <div class="text-right">
                    @if(isset($info['prevPrice']))
                        <div class="text-base font-semibold text-gray-900">
                            Предыдущее закрытие: {{ number_format($info['prevPrice'], 2, '.', ' ') }}
                        </div>
                    @endif
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="p-3 border rounded">
                    <div class="text-xs text-gray-500">Название</div>
                    <div class="font-medium">{{ $info['name'] ?? '—' }}</div>
                </div>
                <div class="p-3 border rounded">
                    <div class="text-xs text-gray-500">ISIN</div>
                    <div class="font-medium">{{ $info['isin'] ?? '—' }}</div>
                </div>
            </div>
        </section>
    </div>
</body>
</html>
