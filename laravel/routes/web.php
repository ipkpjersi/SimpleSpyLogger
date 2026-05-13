<?php

use App\Http\Controllers\InviteCodeController;
use App\Http\Controllers\PasswordSecurityController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

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

Route::get('/', function () {
    if (Auth::user() !== null) {
        return redirect('home');
    }

    return view('welcome');
})->name('welcome');

Route::get('/home', function () {
    if (Auth::user() === null) {
        return view('welcome');
    }

    return redirect('dashboard');
})->name('home');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', '2fa'])->name('dashboard');

Route::middleware('auth', '2fa')->group(function () {
    Route::get('/2fa', [PasswordSecurityController::class, 'show2faForm'])->name('2fa');
    Route::post('/generate2faSecret', [PasswordSecurityController::class, 'generate2faSecret'])->name('generate2faSecret');
    Route::post('/2fa', [PasswordSecurityController::class, 'enable2fa'])->name('enable2fa');
    Route::post('/disable2fa', [PasswordSecurityController::class, 'disable2fa'])->name('disable2fa');
    Route::match(['get', 'post'], '/2faVerify', function () {
        return redirect(str_contains(URL()->previous(), '2faVerify') ? '/' : URL()->previous());
    })->name('2faVerify')->middleware('2fa');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::post('/invite-codes/generate-invite-codes', [InviteCodeController::class, 'generateInviteCodes'])->name('generate-invite-codes');
    Route::post('/invite-codes/revoke-unused-invite-codes', [InviteCodeController::class, 'revokeUnusedInviteCodes'])->name('revoke-unused-invite-codes');
    Route::get('/invite-codes', [InviteCodeController::class, 'index'])->name('invite-codes-index');
    Route::get('/invite-codes/data', [InviteCodeController::class, 'data'])->name('invite-codes-data');
});

require __DIR__.'/auth.php';
