<?php

namespace App\Services;

use App\Exceptions\AiRateLimitException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiChatOrchestrator
{
    // Kalau Gemini kena limit, "istirahatkan" dulu selama N menit
    // supaya request berikutnya tidak percuma coba Gemini lagi dulu
    // (hemat 1 round-trip network yang pasti gagal).
    protected int $cooldownMinutes = 5;

    public function __construct(
        protected GeminiChatService $gemini,
        protected GroqChatService $groq,
    ) {}

    public function send(array $history, string $userMessage, ?array $userLocation = null, ?array $knownSlots = null): array
    {
        if (! Cache::has('gemini:rate-limited')) {
            try {
                $result = $this->gemini->send($history, $userMessage, $userLocation, $knownSlots);
                return array_merge($result, ['provider' => 'gemini']);
            } catch (AiRateLimitException $e) {
                Log::info('Gemini limit, fallback ke Groq');
                Cache::put('gemini:rate-limited', true, now()->addMinutes($this->cooldownMinutes));
            } catch (ConnectionException|Throwable $e) {
                Log::error('Gemini error tak terduga, fallback ke Groq', ['error' => $e->getMessage()]);
            }
        }

        if (! Cache::has('groq:rate-limited')) {
            try {
                $result = $this->groq->send($history, $userMessage, $userLocation, $knownSlots);
                return array_merge($result, ['provider' => 'groq']);
            } catch (AiRateLimitException $e) {
                Log::error('Groq juga limit');
                Cache::put('groq:rate-limited', true, now()->addMinutes($this->cooldownMinutes));
            } catch (ConnectionException|Throwable $e) {
                Log::error('Groq error tak terduga', ['error' => $e->getMessage()]);
            }
        }

        return [
            'text' => 'Mohon maaf, layanan sedang sangat sibuk. Silakan coba beberapa saat lagi ya 🙏',
            'payload' => null,
            'provider' => null,
        ];
    }
}