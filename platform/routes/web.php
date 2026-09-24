<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RecoveryAmountController;

Route::get('/incidents/{incident}/recovery-amount', [RecoveryAmountController::class, 'show']);

Route::get('/', function () {
    return view('welcome');
});
