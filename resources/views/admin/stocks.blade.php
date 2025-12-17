<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление ценными бумагами</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans bg-gray-50 text-gray-900">
    <div class="max-w-6xl mx-auto px-4 py-6">
        <header class="mb-6">
            <div class="flex justify-between items-center mb-2">
                <div class="flex items-center gap-3">
                    <a href="{{ route('securities.index') }}" class="text-gray-600 hover:text-gray-900 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                    </a>
                    <h1 class="text-2xl font-semibold">Управление ценными бумагами</h1>
                </div>
                <div class="flex items-center gap-4">
                    <span class="text-sm text-gray-600">
                        Администратор
                    </span>
                    <button type="button" id="logout-btn" class="px-4 py-2 text-sm bg-red-600 text-white rounded hover:bg-red-700">
                        Выйти
                    </button>
                </div>
            </div>
        </header>

        @if(session('success'))
            <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded text-green-700">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div id="error-message" class="mb-4 p-4 bg-red-50 border border-red-200 rounded text-red-700">
                {{ session('error') }}
            </div>
        @endif

        @if($errors->any())
            <div id="validation-error-message" class="mb-4 p-4 bg-red-50 border border-red-200 rounded text-red-700">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Форма добавления нового тикера -->
        <section class="bg-white shadow rounded-lg p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">Добавить ценную бумагу</h2>
            <form method="POST" action="{{ route('admin.stocks.add') }}" class="flex gap-4">
                @csrf
                <div class="flex-1">
                    <div class="flex items-center justify-between mb-2">
                        <label for="ticker" class="block text-sm font-medium text-gray-700">Ценная бумага</label>
                        <a href="https://iss.moex.com/iss/engines/stock/markets/shares/boards/TQBR/securities/" target="_blank" class="text-sm text-blue-600 hover:text-blue-800">
                            Все ценные бумаги
                        </a>
                    </div>
                    <input 
                        type="text" 
                        id="ticker" 
                        name="ticker" 
                        required
                        maxlength="10"
                        placeholder="Например: SBER"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                </div>
                <div class="flex items-end">
                    <button 
                        type="submit" 
                        class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                        Добавить
                    </button>
                </div>
            </form>
        </section>

        <!-- Список тикеров -->
        <section class="bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Список ценных бумаг</h2>
            
            @if($stocks->isEmpty())
                <p class="text-gray-500 text-center py-8">Нет доступных ценных бумаг</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Тикер</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Название</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ISIN</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Действия</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($stocks as $stock)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        {{ $stock->ticker }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $stock->name ?? '—' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $stock->isin ?? '—' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <div class="flex items-center gap-2">
                                            <form method="POST" action="{{ route('admin.stocks.update', $stock) }}" class="inline">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="is_available" value="{{ $stock->is_available ? '0' : '1' }}">
                                                <button 
                                                    type="submit" 
                                                    class="px-4 py-2 text-sm rounded-md {{ $stock->is_available ? 'bg-red-600 hover:bg-red-700' : 'bg-green-600 hover:bg-green-700' }} text-white"
                                                >
                                                    {{ $stock->is_available ? 'Отключить' : 'Включить' }}
                                                </button>
                                            </form>
                                            <button 
                                                type="button" 
                                                class="px-4 py-2 text-sm rounded-md bg-red-600 hover:bg-red-700 text-white delete-stock-btn"
                                                data-ticker="{{ $stock->ticker }}"
                                                data-stock-id="{{ $stock->id }}"
                                            >
                                                Удалить
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <!-- Модальное окно подтверждения выхода -->
    <div id="logout-modal" class="hidden fixed inset-0 bg-opacity-30 overflow-y-auto h-full w-full z-50">
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

    <!-- Модальное окно подтверждения удаления -->
    <div id="delete-modal" class="hidden fixed inset-0 bg-opacity-30 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 text-center mb-2">Подтверждение удаления</h3>
                <div class="mt-2 px-4 py-3">
                    <p class="text-sm text-gray-500 text-center">
                        Вы уверены, что хотите удалить ценную бумагу <span id="delete-ticker-name" class="font-semibold text-gray-900"></span>?
                    </p>
                </div>
                <div class="flex gap-3 px-4 py-3">
                    <form id="delete-form" method="POST" class="flex-1">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="w-full px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500">
                            Да, удалить
                        </button>
                    </form>
                    <button type="button" id="cancel-delete" class="flex-1 px-4 py-2 bg-gray-300 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500">
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
        
        document.getElementById('logout-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.add('hidden');
            }
        });

        // Обработка модального окна удаления
        const deleteButtons = document.querySelectorAll('.delete-stock-btn');
        const deleteModal = document.getElementById('delete-modal');
        const deleteForm = document.getElementById('delete-form');
        const deleteTickerName = document.getElementById('delete-ticker-name');
        const cancelDeleteBtn = document.getElementById('cancel-delete');

        deleteButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                const ticker = this.getAttribute('data-ticker');
                const stockId = this.getAttribute('data-stock-id');
                
                deleteTickerName.textContent = ticker;
                deleteForm.action = '{{ url("/admin/stocks") }}/' + stockId;
                deleteModal.classList.remove('hidden');
            });
        });

        cancelDeleteBtn.addEventListener('click', function() {
            deleteModal.classList.add('hidden');
        });

        deleteModal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.add('hidden');
            }
        });

        // Автоматическое скрытие сообщений через 3 секунды
        const successMessage = document.getElementById('success-message');
        const errorMessage = document.getElementById('error-message');
        const validationErrorMessage = document.getElementById('validation-error-message');
        
        if (successMessage) {
            setTimeout(function() {
                successMessage.style.transition = 'opacity 0.5s';
                successMessage.style.opacity = '0';
                setTimeout(function() {
                    successMessage.remove();
                }, 500);
            }, 3000);
        }
        
        if (errorMessage) {
            setTimeout(function() {
                errorMessage.style.transition = 'opacity 0.5s';
                errorMessage.style.opacity = '0';
                setTimeout(function() {
                    errorMessage.remove();
                }, 500);
            }, 3000);
        }

        if (validationErrorMessage) {
            setTimeout(function() {
                validationErrorMessage.style.transition = 'opacity 0.5s';
                validationErrorMessage.style.opacity = '0';
                setTimeout(function() {
                    validationErrorMessage.remove();
                }, 500);
            }, 3000);
        }
    </script>
</body>
</html>

