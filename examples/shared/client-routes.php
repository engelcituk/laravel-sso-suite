<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use LaravelSso\Examples\Report;
use LaravelSso\Examples\ReportPolicy;

Gate::policy(Report::class, ReportPolicy::class);
Gate::define('view-reports', fn ($user) => Gate::forUser($user)->allows('view', new Report));
Route::get('/', fn () => view()->file(dirname(__DIR__).'/shared/client-home.blade.php'));
Route::middleware(['auth', 'sso.session:strict'])->group(function (): void {
    Route::get('/me', fn () => [
        'local_id' => auth()->id(), 'issuer' => auth()->user()->sso_issuer,
        'subject' => auth()->user()->sso_subject, 'can_view_reports' => Gate::allows('view-reports'),
    ]);
    Route::get('/reports', function () {
        Gate::authorize('view', new Report);

        return ['report' => 'Autorizado únicamente por la ACL local.'];
    });
});
