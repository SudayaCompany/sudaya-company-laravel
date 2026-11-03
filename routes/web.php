<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('welcome', [
    'app' => ['name' => 'Sudaya Coffee', 'framework' => 'Laravel '.app()->version()],
]));
