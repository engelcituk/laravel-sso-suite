<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::view('/login', 'login')->name('login');
Route::post('/login', function (Request $request) {
    $input = $request->validate(['email' => 'required|email', 'password' => 'required|string', 'remember' => 'sometimes|boolean']);
    $credentials = array_intersect_key($input, array_flip(['email', 'password']));
    if (! Auth::attempt($credentials + ['is_active' => true], false)) {
        return back()->withErrors(['email' => 'Credenciales inválidas.']);
    }
    $request->session()->regenerate();

    return redirect()->intended('/');
})->middleware('throttle:5,1,central-login');
Route::view('/', 'home');
