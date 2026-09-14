<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MovieController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/movies', [MovieController::class, 'index']);
Route::get('/movies/{id}', [MovieController::class, 'show']);

// CSV 两阶段导入：先预览校验，管理员确认后才真正写入
Route::post('/movies/import/preview', [MovieController::class, 'preview']);
Route::post('/movies/import/confirm', [MovieController::class, 'confirmImport']);

Route::get('/proxy-image', [MovieController::class, 'proxyImage']);

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});