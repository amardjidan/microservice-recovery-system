<?php

use Illuminate\Support\Facades\Route;

// Antarmuka audit ada di panel Filament: /admin
Route::get('/', fn () => redirect('/admin'));
