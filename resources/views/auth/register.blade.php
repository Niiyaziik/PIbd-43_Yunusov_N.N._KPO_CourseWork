<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans bg-gray-50 text-gray-900">
    <div class="min-h-screen flex items-center justify-center px-4 py-8">
        <div class="max-w-md w-full bg-white shadow-lg rounded-lg p-8">
            <h1 class="text-2xl font-semibold mb-6 text-center">Регистрация</h1>

            @if ($errors->any())
                <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded text-red-700">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('register') }}">
                @csrf

                <div class="mb-4">
                    <label for="login" class="block text-sm font-medium text-gray-700 mb-2">Логин</label>
                    <input 
                        type="text" 
                        id="login" 
                        name="login" 
                        value="{{ old('login') }}"
                        required 
                        autofocus
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                </div>

                <div class="mb-4">
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Почта</label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        value="{{ old('email') }}"
                        required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                </div>

                <div class="mb-4">
                    <label for="first_name" class="block text-sm font-medium text-gray-700 mb-2">Имя</label>
                    <input 
                        type="text" 
                        id="first_name" 
                        name="first_name" 
                        value="{{ old('first_name') }}"
                        required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                </div>

                <div class="mb-4">
                    <label for="last_name" class="block text-sm font-medium text-gray-700 mb-2">Фамилия</label>
                    <input 
                        type="text" 
                        id="last_name" 
                        name="last_name" 
                        value="{{ old('last_name') }}"
                        required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                </div>

                <div class="mb-4">
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">Пароль</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                </div>

                <div class="mb-6">
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-2">Подтверждение пароля</label>
                    <input 
                        type="password" 
                        id="password_confirmation" 
                        name="password_confirmation" 
                        required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                </div>

                <button 
                    type="submit" 
                    class="w-full bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    Зарегистрироваться
                </button>
            </form>

            <div class="mt-6 text-center">
                <p class="text-sm text-gray-600">
                    Уже есть аккаунт? 
                    <a href="{{ route('login') }}" class="text-blue-600 hover:text-blue-800 font-medium">
                        Войти
                    </a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>

