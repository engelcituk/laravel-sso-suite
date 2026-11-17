<?php

use Illuminate\Support\Facades\Gate;
use LaravelSso\Examples\Report;
use LaravelSso\Examples\ReportPolicy;

// Authorization must boot even when Laravel loads its cached routes.
Gate::policy(Report::class, ReportPolicy::class);
Gate::define('view-reports', fn ($user) => Gate::forUser($user)->allows('view', new Report));
