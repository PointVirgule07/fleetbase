<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DriverAuthController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware(['fleetbase.api', 'Fleetbase\FleetOps\Http\Middleware\TransformLocationMiddleware'])
    ->post('v1/drivers/login-with-sms', [DriverAuthController::class, 'loginWithPhone']);
