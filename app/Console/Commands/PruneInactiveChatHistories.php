<?php

namespace App\Console\Commands;

use App\Models\ChatHistory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('chat:prune-inactive {--minutes=15}')]
#[Description('Hapus riwayat chat milik guest yang sudah tidak aktif selama N menit')]
class PruneInactiveChatHistories extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $threshold = now()->subMinutes($minutes);

        // cari guest_id yang pesan TERAKHIRnya sudah lebih tua dari threshold
        $inactiveGuestIds = ChatHistory::select('guest_id')
            ->groupBy('guest_id')
            ->havingRaw('MAX(created_at) < ?', [$threshold])
            ->pluck('guest_id');

        if ($inactiveGuestIds->isEmpty()) {
            $this->info('Tidak ada sesi chat yang perlu dihapus.');
            return self::SUCCESS;
        }

        $deleted = 0;

        // chunk supaya query IN(...) tidak membengkak kalau guest tidak aktif jumlahnya banyak
        foreach ($inactiveGuestIds->chunk(500) as $chunk) {
            $deleted += ChatHistory::whereIn('guest_id', $chunk)->delete();
        }

        $this->info("Berhasil menghapus {$deleted} pesan dari {$inactiveGuestIds->count()} sesi tidak aktif.");

        return self::SUCCESS;
    }
}
