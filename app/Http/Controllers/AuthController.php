<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Показать форму входа
     */
    public function showLoginForm()
    {
        return view('auth.login');
    }

    /**
     * Обработка входа
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Пытаемся найти пользователя по логину
        $user = User::where('login', $credentials['login'])->first();

        if ($user && Hash::check($credentials['password'], $user->password)) {
            Auth::login($user, $request->boolean('remember'));
            $request->session()->regenerate();

            return redirect()->intended(route('securities.index'));
        }

        return back()->withErrors([
            'login' => 'Неверный логин или пароль.',
        ])->onlyInput('login');
    }

    /**
     * Показать форму регистрации
     */
    public function showRegisterForm()
    {
        return view('auth.register');
    }

    /**
     * Обработка регистрации
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'login' => ['required', 'string', 'max:255', 'unique:users,login'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ], [
            'password.min' => 'Пароль должен содержать не менее :min символов.',
            'password.confirmed' => 'Подтверждение пароля не совпадает.',
        ]);

        $user = User::create([
            'login' => $validated['login'],
            'name' => $validated['first_name'] . ' ' . $validated['last_name'],
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('securities.index');
    }

    /**
     * Выход из системы
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

