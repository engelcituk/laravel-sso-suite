<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::view('/login', 'login')->name('login');
Route::post('/login', function (Request $request) {
    $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
    if (! Auth::attempt($credentials + ['is_active' => true], false)) {
        return back()->withErrors(['email' => 'Credenciales inválidas.']);
    }
    $request->session()->regenerate();

    return redirect()->intended('/');
})->middleware('throttle:5,1,central-login');
Route::get('/', fn () => 'Identity Provider de desarrollo. Accede desde una aplicación cliente.');
