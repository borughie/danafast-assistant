<?php

use App\Models\ChatHistory;
use App\Services\GeminiChatService;
use App\Services\AiChatOrchestrator;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;
use Flux\Flux;

new class extends Component {
    public string $prompt = '';
    public array $messages = [];
    public bool $isTyping = false;
    public string $guestId = '';
    public ?float $userLat = null;
    public ?float $userLng = null;

    public function renderMarkdown(string $text): string
    {
        $html = Str::markdown($text, ['html_input' => 'strip']);

        return clean($html);
    }

    public function mount(): void
    {
        $this->guestId = request()->cookie('chat_guest_id') ?? (string) Str::uuid();

        if (!request()->cookie('chat_guest_id')) {
            Cookie::queue('chat_guest_id', $this->guestId, 60 * 24 * 365);
        }

        $lastMessage = ChatHistory::where('guest_id', $this->guestId)
            ->latest('created_at')
            ->first();

        if ($lastMessage && $lastMessage->created_at->lt(now()->subMinutes(15))) {
            ChatHistory::where('guest_id', $this->guestId)->delete();
        }

        $existing = ChatHistory::where('guest_id', $this->guestId)
            ->orderBy('created_at')
            ->get(['id', 'role', 'message', 'payload', 'provider', 'created_at']);

        if ($existing->isEmpty()) {
            $this->storeMessage('model', $this->pesanSapaan());
        }

        $this->messages = ChatHistory::where('guest_id', $this->guestId)
            ->orderBy('created_at')
            ->get(['id', 'role', 'message', 'payload', 'provider', 'created_at'])
            ->map(fn($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'message' => $m->message,
                'payload' => $m->payload,
                'provider' => $m->provider,
                'created_at' => $m->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    /**
     * Step 1: simpan pesan user & tampilkan indikator "mengetik".
     * Method ini HARUS ringan & cepat (tanpa panggil API eksternal)
     * supaya user langsung lihat pesannya tampil.
     */
    public function sendMessage(): void
    {
        $this->validate(['prompt' => 'required|string|max:1000']);

        $key = "chat-send:{$this->guestId}";

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $seconds = RateLimiter::availableIn($key);
            $this->storeMessage('model', "Mohon tunggu {$seconds} detik sebelum mengirim pesan lagi ya 🙏");
            $this->dispatch('scroll-bottom');
            $this->dispatch('focus-input');
            return;
        }

        RateLimiter::hit($key, 60); // maks 10 pesan per 60 detik

        $userMessage = trim($this->prompt);
        $this->prompt = '';

        $this->storeMessage('user', $userMessage);
        $this->isTyping = true;

        $this->dispatch('scroll-bottom');
    }

    /**
     * Step 2: baru panggil Gemini API (lambat), dipanggil setelah
     * step 1 selesai render di browser.
     */
    public function getReply(AiChatOrchestrator $ai): void
    {
        $lastUserMessage = collect($this->messages)
            ->where('role', 'user')
            ->last()['message'] ?? null;

        if (!$lastUserMessage) {
            $this->isTyping = false;
            $this->dispatch('focus-input');
            return;
        }

        $history = collect($this->messages)
            ->reject(fn($m, $i) => $i === array_key_last($this->messages))
            ->toArray();

        $result = $ai->send($history, $lastUserMessage, [
            'lat' => $this->userLat,
            'lng' => $this->userLng,
        ], $this->extractKnownSlots());

        $this->storeMessage('model', $result['text'], $result['payload'], $result['provider'] ?? null);
        $this->isTyping = false;

        $this->dispatch('scroll-bottom');
        $this->dispatch('focus-input');
    }

    protected function storeMessage(string $role, string $message, ?array $payload = null, ?string $provider = null): void
    {
        $record = ChatHistory::create([
            'guest_id' => $this->guestId,
            'role' => $role,
            'message' => $message,
            'payload' => $payload,
            'provider' => $provider,
        ]);

        $this->messages[] = [
            'id' => $record->id,
            'role' => $role,
            'message' => $message,
            'payload' => $payload,
            'provider' => $provider,
            'created_at' => $record->created_at->toIso8601String(),
        ];
    }

    protected function extractKnownSlots(): array
    {
        $lastQuickReply = collect($this->messages)->last(fn($m) => ($m['payload']['type'] ?? null) === 'quick_reply');
        $lastSimulasi = collect($this->messages)->last(fn($m) => ($m['payload']['type'] ?? null) === 'simulasi');

        return [
            'produk_direkomendasikan' => $lastQuickReply['payload']['data']['produk'] ?? null,
            'simulasi_sudah_dihitung' => $lastSimulasi !== null,
        ];
    }

    public function formatWaktuIndonesia(string $isoTime): string
    {
        $time = \Carbon\Carbon::parse($isoTime);

        $periode = match (true) {
            $time->hour < 11 => 'Pagi',
            $time->hour < 15 => 'Siang',
            $time->hour < 18 => 'Sore',
            default => 'Malam',
        };

        return $time->format('g') . '.' . $time->format('i') . ' ' . $periode;
    }

    /**
     * Menghapus semua pesan setelah $messageId (DB + array in-memory).
     * $keepMessage = true  -> untuk RETRY (pesan tetap ada, balasan lama dihapus)
     * $keepMessage = false -> untuk EDIT  (pesan itu sendiri ikut dihapus, user ketik ulang)
     */
    protected function truncateAfter(int $messageId, bool $keepMessage): void
    {
        $index = collect($this->messages)->search(fn($m) => $m['id'] === $messageId);

        if ($index === false) {
            return;
        }

        $cutIndex = $keepMessage ? $index + 1 : $index;

        $idsToDelete = collect($this->messages)->slice($cutIndex)->pluck('id');
        ChatHistory::whereIn('id', $idsToDelete)->delete();

        $this->messages = collect($this->messages)->slice(0, $cutIndex)->values()->toArray();
    }

    protected function pesanSapaan(): string
    {
        return 'Halo! Saya Danafast Assistant 👋 Saya bisa bantu Anda seputar produk kredit BPR Danafast, simulasi angsuran, sampai mencari lokasi kantor cabang terdekat. Ada yang bisa saya bantu?';
    }

    /**
     * Step 1 retry: buang balasan lama, tampilkan "sedang mengetik" lagi.
     * Karena pesan user ini otomatis jadi "pesan user terakhir" setelah
     * dipotong, method getReply() yang sudah ada bisa langsung dipakai lagi
     * tanpa perubahan apa pun.
     */
    public function retryMessage(int $messageId): void
    {
        $this->truncateAfter($messageId, keepMessage: true);
        $this->isTyping = true;
        $this->dispatch('scroll-bottom');
    }

    /**
     * Edit: isi ulang komposer dengan teks lama, lalu hapus pesan tsb
     * beserta semua yang setelahnya. User tinggal edit & kirim ulang
     * lewat form yang sudah ada (sendMessage -> getReply).
     */
    public function editMessage(int $messageId): void
    {
        $target = collect($this->messages)->firstWhere('id', $messageId);

        if (!$target) {
            return;
        }

        $this->prompt = $target['message'];
        $this->truncateAfter($messageId, keepMessage: false);
        $this->dispatch('scroll-bottom');
        $this->dispatch('focus-input');
    }

    public function resetChat(): void
    {
        ChatHistory::where('guest_id', $this->guestId)->delete();

        $this->messages = [];
        $this->isTyping = false;
        $this->prompt = '';

        $this->storeMessage('model', $this->pesanSapaan());

        Flux::modal('delete-chat')->close();

        Flux::toast(
            heading: 'Chat berhasil dihapus',
            text: 'Percakapan baru telah dimulai.',
            variant: 'success',
        );

        $this->dispatch('scroll-bottom');
    }
};
?>
<div x-data="{
        idleTimer: null,
        resetIdleTimer() {
            clearTimeout(this.idleTimer);
            this.idleTimer = setTimeout(() => {
                window.location.reload();
            }, 15 * 60 * 1000);
        },
        initGeolocation() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    (pos) => {
                        $wire.set('userLat', pos.coords.latitude);
                        $wire.set('userLng', pos.coords.longitude);
                    },
                    () => { /* user menolak izin lokasi, biarkan null (fallback ke kantor default) */ }
                );
            }
        }
     }" x-init="resetIdleTimer(); initGeolocation()" x-on:mousemove.window="resetIdleTimer()"
    x-on:keydown.window="resetIdleTimer()">

    <div x-data x-ref="chatWrapper"
        x-on:scroll-bottom.window="setTimeout(() => $refs.scrollArea.scrollTop = $refs.scrollArea.scrollHeight, 50)"
        x-on:focus-input.window="setTimeout(() => {
            const el = $refs.promptForm?.querySelector('textarea, [contenteditable=true]');
            if (el) el.focus();
        }, 50)" class="flex flex-col h-dvh max-w-2xl mx-auto">
        {{-- HEADER — sticky, dengan shadow sebagai pemisah --}}
        <div
            class="sticky top-0 z-10 flex justify-between items-center p-3 bg-white/15 dark:bg-zinc-800 shadow-sm border-b border-zinc-200 dark:border-zinc-700">
            <div>
                <flux:heading size="lg">Danafast Assistant</flux:heading>
                <flux:text class="text-zinc-500">Tanya produk kredit atau coba simulasi angsuran BPR Danafast
                </flux:text>
            </div>
            <flux:button.group>
                <flux:modal.trigger name="delete-chat">
                    <flux:tooltip interactive content="Hapus Chat">
                        <flux:button square size="sm" class="hover:text-red-500/85 cursor-pointer">
                            <flux:icon.trash variant="solid" class="size-4" />
                        </flux:button>
                    </flux:tooltip>
                </flux:modal.trigger>
                <flux:tooltip interactive content="Ganti Tema">
                    <flux:button x-data x-on:click="$flux.dark = ! $flux.dark" aria-label="Toggle dark mode"
                        class="cursor-pointer text-accent" square size="sm">
                        <flux:icon.moon x-show="!$flux.dark" variant="solid" x-cloak class="size-4" />
                        <flux:icon.sun x-show="$flux.dark" variant="solid" x-cloak class="size-4" />
                    </flux:button>
                </flux:tooltip>
            </flux:button.group>
        </div>

        {{-- DISCLAIMER BANNER --}}
        <div class="px-3 pt-3">
            <flux:callout variant="warning" icon="exclamation-triangle" heading="Proyek Edukasi" size="sm">
                <flux:text size="sm">
                    Ini adalah <strong>proyek tugas akhir</strong>, <strong>bukan</strong> situs resmi BPR Danafast.
                    Data yang ditampilkan bersifat simulasi dan tidak terkait langsung dengan bank.
                </flux:text>
            </flux:callout>
        </div>

        {{-- CHAT AREA — satu-satunya bagian yang scroll --}}
        <div x-ref="scrollArea" class="flex-1 overflow-y-auto p-3 space-y-3 my-3!">
            @foreach ($messages as $msg)
                    @php
                        $isLastUserMessage = $msg['role'] === 'user'
                            && $msg['id'] === (collect($messages)->where('role', 'user')->last()['id'] ?? null);
                    @endphp

                    <div class="flex flex-col {{ $msg['role'] === 'user' ? 'items-end' : 'items-start' }} gap-1 group">
                        {{-- BUBBLE --}}
                        <div class="max-w-[80%] px-4 py-2.5 rounded-2xl text-sm {{ $msg['role'] === 'user'
                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                : 'bg-zinc-100 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-100' }}">

                            @if ($msg['role'] === 'model')
                                <div
                                    class="prose prose-sm dark:prose-invert max-w-none prose-p:m-0 prose-headings:mt-2 prose-headings:mb-1 prose-ul:my-1 prose-ol:my-1 prose-li:my-0">
                                    {!! $this->renderMarkdown($msg['message']) !!}
                                </div>

                                @if (!empty($msg['payload']))
                                    @php
                                        $type = $msg['payload']['type'] ?? null;
                                        $data = $msg['payload']['data'] ?? [];
                                    @endphp

                                    @if ($type === 'location')
                                        <div x-data x-init="setTimeout(() => {
                                                                                                            const map = L.map($el).setView([{{ $data['lat'] }}, {{ $data['lng'] }}], 15);
                                                                                                            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                                                                                                attribution: '&copy; OpenStreetMap contributors'
                                                                                                            }).addTo(map);
                                                                                                            L.marker([{{ $data['lat'] }}, {{ $data['lng'] }}]).addTo(map).bindPopup('{{ addslashes($data['nama']) }}');
                                                                                                        }, 100)"
                                            class="mt-2 rounded-lg overflow-hidden" style="height: 200px; width: 100%;">
                                        </div>
                                        <a href="https://www.google.com/maps?q={{ $data['lat'] }},{{ $data['lng'] }}" target="_blank"
                                            class="text-xs text-blue-600 dark:text-blue-400 underline mt-1 inline-block">
                                            Buka di Google Maps
                                        </a>
                                    @endif

                                    @if ($type === 'simulasi')
                                        <a href="{{ route('chat.simulasi.pdf', $msg['id']) }}" target="_blank"
                                            class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-full bg-white/10 hover:bg-white/20 dark:bg-black/10 border border-current/20 transition">
                                            📄 Unduh Simulasi (PDF)
                                        </a>
                                    @endif

                                    @if ($type === 'quick_reply')
                                        <div class="flex flex-wrap gap-2 mt-2">
                                            <button type="button"
                                                x-on:click="await $wire.set('prompt', 'Ya, tolong hitungkan simulasi angsuran untuk {{ $data['produk'] }}'); await $wire.sendMessage(); await $wire.getReply()"
                                                class="text-xs px-3 py-1.5 rounded-full bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900 hover:opacity-80 transition">
                                                Ya, hitung simulasi
                                            </button>
                                            <button type="button"
                                                x-on:click="await $wire.set('prompt', 'Saya mau lihat produk lain'); await $wire.sendMessage(); await $wire.getReply()"
                                                class="text-xs px-3 py-1.5 rounded-full border border-zinc-300 dark:border-zinc-600 hover:bg-zinc-100 dark:hover:bg-zinc-700 transition">
                                                Produk lain
                                            </button>
                                        </div>
                                    @endif

                                    @if ($type === 'tenor_options')
                                        <div class="flex flex-wrap gap-2 mt-2">
                                            @foreach ($data['opsi'] as $opsi)
                                                <button type="button"
                                                    x-on:click="await $wire.set('prompt', '{{ $opsi }} bulan'); await $wire.sendMessage(); await $wire.getReply()"
                                                    class="text-xs px-3 py-1.5 rounded-full border border-zinc-300 dark:border-zinc-600 hover:bg-zinc-100 dark:hover:bg-zinc-700 transition">
                                                    {{ $opsi }} Bulan
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                @endif
                            @else
                                {{ $msg['message'] }}
                            @endif
                        </div>

                        {{-- BARIS JAM + AKSI — sekarang hover-only --}}
                        <div class="flex items-center gap-1 px-1 text-[11px] text-zinc-400 opacity-0 group-hover:opacity-100 transition-opacity duration-150"
                            x-data="{ copied: false }">
                            @if ($msg['role'] === 'model' && !empty($msg['provider']))
                                        <span class="select-none px-1.5 py-0.5 rounded-full text-[10px] font-medium {{ $msg['provider'] === 'gemini'
                                ? 'bg-blue-500/10 text-blue-500'
                                : 'bg-orange-500/10 text-orange-500' }}">
                                            {{ $msg['provider'] === 'gemini' ? 'Gemini' : 'Groq' }}
                                        </span>
                            @endif

                            <span class="select-none">{{ $this->formatWaktuIndonesia($msg['created_at']) }}</span>

                            <flux:tooltip interactive content="Salin">
                                <flux:button size="xs" variant="ghost" square class="cursor-pointer"
                                    x-on:click="navigator.clipboard.writeText(@js($msg['message'])); copied = true; setTimeout(() => copied = false, 1500)">
                                    <flux:icon.check x-show="copied" x-cloak class="size-3.5" />
                                    <flux:icon.clipboard-document variant="solid" x-show="!copied" x-cloak class="size-3.5" />
                                </flux:button>
                            </flux:tooltip>

                            @if ($isLastUserMessage)
                                <flux:tooltip interactive content="Edit pesan">
                                    <flux:button size="xs" variant="ghost" square class="cursor-pointer" icon="pencil"
                                        wire:loading.attr="disabled" wire:target="sendMessage,getReply,editMessage,retryMessage"
                                        x-on:click="await $wire.editMessage({{ $msg['id'] }})" />
                                </flux:tooltip>

                                <flux:tooltip interactive content="Kirim ulang">
                                    <flux:button size="xs" variant="ghost" class="cursor-pointer" square icon="arrow-path"
                                        wire:loading.attr="disabled" wire:target="sendMessage,getReply,editMessage,retryMessage"
                                        x-on:click="await $wire.retryMessage({{ $msg['id'] }}); await $wire.getReply()" />
                                </flux:tooltip>
                            @endif
                        </div>
                    </div>
            @endforeach

            @if ($isTyping)
                <div class="flex justify-start">
                    <div class="px-4 py-2.5 rounded-2xl bg-zinc-100 dark:bg-zinc-700 text-zinc-500 text-sm italic">
                        Danafast Assistant sedang mengetik...
                    </div>
                </div>
            @endif
        </div>

        {{-- FORM — sticky di bawah --}}
        <div
            class="sticky bottom-0 z-10 p-2 border-t border-zinc-200 dark:border-zinc-700 pb-[calc(0.75rem+env(safe-area-inset-bottom))]">
            <form x-ref="promptForm" x-on:submit.prevent="await $wire.sendMessage(); await $wire.getReply()">
                <flux:composer wire:model="prompt" submit="enter" rows="1" inline label="Prompt" label:sr-only
                    placeholder="Contoh: mau pinjam 20 juta, tenor 24 bulan" wire:loading.attr="disabled"
                    wire:target="sendMessage,getReply">
                    <x-slot name="actionsTrailing">
                        <flux:button type="submit" size="sm" variant="primary" icon="paper-airplane"
                            wire:loading.attr="disabled" wire:target="sendMessage,getReply" />
                    </x-slot>
                </flux:composer>
            </form>
        </div>
    </div>

    <flux:modal name="delete-chat" class="md:w-96">
        <div class="space-y-2">
            <div>
                <flux:heading size="lg">Hapus Chat</flux:heading>
                <flux:text class="mt-2">Apakah kamu yakin ingin menghapus chat ini?</flux:text>
            </div>
            <div class="flex gap-2 mt-4">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="filled" size="sm" class="cursor-pointer">Batal</flux:button>
                </flux:modal.close>
                <flux:button size="sm" type="button" variant="danger" icon="trash" class="cursor-pointer"
                    wire:click="resetChat" wire:loading.attr="disabled" wire:target="resetChat">
                    Hapus
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>