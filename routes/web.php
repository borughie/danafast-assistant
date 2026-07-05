<?php

use Illuminate\Support\Facades\Route;
use App\Models\ChatHistory;
use Barryvdh\DomPDF\Facade\Pdf;

Route::middleware(['throttle:60,1'])->group(function () {
    Route::livewire('/', 'pages::home')->name('home');
    Route::get('/chat/simulasi/{chatHistory}/pdf', function (ChatHistory $chatHistory) {
        abort_unless($chatHistory->guest_id === request()->cookie('chat_guest_id'), 403);
        abort_unless(($chatHistory->payload['type'] ?? null) === 'simulasi', 404);

        $data = $chatHistory->payload['data'];

        $pdf = Pdf::loadView('pdf.simulasi-kredit', ['data' => $data])->setPaper('a4');

        return $pdf->download('simulasi-kredit-danafast-' . now()->format('Ymd-His') . '.pdf');
    })->name('chat.simulasi.pdf');
});