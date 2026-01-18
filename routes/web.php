<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\CheckAdminMiddleware;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/


Route::post('/login_custom', [ProfileController::class, 'login_custom'])->name('login_custom');
Route::get('/video', function () {
    return view('video');
})->name('video');

Route::get('/video1', function () {
    return view('video1');
})->name('video1');

Route::middleware([CheckAdminMiddleware::class ])->group(function () {
    Route::view('/dashboard', 'videos.upload')->name('videos.upload');
    Route::view('/', 'videos.upload')->name('dashboard');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/logOut', [ProfileController::class, 'destroy'])->name('logOut');
});

require __DIR__.'/auth.php';
