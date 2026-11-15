<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use LaravelSso\Examples\Report;
use LaravelSso\Examples\ReportPolicy;

Gate::policy(Report::class, ReportPolicy::class);
Gate::define('view-reports', fn ($user) => Gate::forUser($user)->allows('view', new Report));
Route::get('/', fn () => view()->file(dirname(__DIR__).'/shared/client-home.blade.php'));
Route::middleware(['auth', 'sso.session:strict'])->group(function (): void {
    Route::get('/me', fn () => [
        'local_id' => auth()->id(), 'issuer' => session('sso.identity.iss'),
        'subject' => session('sso.identity.sub'), 'can_view_reports' => Gate::allows('view-reports'),
    ]);
    Route::get('/reports', function () {
        Gate::authorize('view', new Report);

        return ['report' => 'Autorizado únicamente por la ACL local.'];
    });
});

// These routes belong to the application; the package never supplies local credentials.
Route::middleware('sso.local')->group(function (): void {
    Route::get('/login', fn () => view()->file(dirname(__DIR__).'/shared/local-login.blade.php'))->name('login');
    Route::post('/login', function (Request $request) {
        $input = $request->validate(['email' => 'required|email', 'password' => 'required|string|max:4096']);
        if (! Auth::attempt($input, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Credenciales locales inválidas.']);
        }
        $request->session()->regenerate();

        return redirect('/me');
    })->name('login.store');
    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    })->name('logout');
});
