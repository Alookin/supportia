<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SupportDashboardController;
use App\Http\Controllers\Api\SupportTicketController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/support')->name('home');

Route::get('/dashboard', function () {
    $user = auth()->user();
    $firstName = explode(' ', trim($user->name))[0];

    $weekTickets = \App\Models\SupportTicket::where('user_id', $user->id)
        ->where('created_at', '>=', now()->startOfWeek())
        ->count();

    $lastTicket = \App\Models\SupportTicket::where('user_id', $user->id)
        ->orderByDesc('created_at')
        ->first();

    return view('dashboard', compact('firstName', 'weekTickets', 'lastTicket'));
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

Route::middleware(['auth', 'org.active'])->prefix('support')->group(function () {
    Route::get('/', function () {
        $user       = auth()->user();
        $categories = $user->organization?->activeCategories()->forTeam($user->team_id)->get() ?? collect();
        return view('support.create', compact('categories'));
    })->name('support.create');

    Route::get('/dashboard', [SupportDashboardController::class, 'index'])->name('support.dashboard');
    Route::get('/mes-tickets', [SupportDashboardController::class, 'myTickets'])->name('support.my-tickets');
    Route::get('/equipe', [SupportDashboardController::class, 'teamTickets'])->name('support.team-tickets');
    Route::get('/tickets/{id}', [SupportDashboardController::class, 'show'])->whereNumber('id')->name('support.ticket-detail');
    Route::post('/tickets/{id}/comment', [SupportDashboardController::class, 'addComment'])->name('support.ticket-comment');
    Route::get('/tickets/{id}/attachments/{attachmentId}', [SupportDashboardController::class, 'downloadAttachment'])->name('support.ticket-attachment');
    Route::get('/demo', fn() => view('support.demo'))->name('support.demo');

    Route::get('/tickets/open-for-client', [SupportTicketController::class, 'openForClient'])->middleware('throttle:60,1')->name('support.open-for-client');
    Route::get('/tickets', [SupportTicketController::class, 'index']);
    Route::post('/tickets', [SupportTicketController::class, 'store'])->middleware('throttle:20,1');
    Route::post('/tickets/{ticket}/confirm', [SupportTicketController::class, 'confirm'])->middleware('throttle:20,1');
});
