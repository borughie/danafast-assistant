<?php

namespace App\Services;

use App\Exceptions\AiRateLimitException;
use App\Services\Concerns\HandlesKreditFunctions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqChatService
{
    use HandlesKreditFunctions;

    protected string $apiKey;
    protected string $model;
    protected string $baseUrl = 'https://api.groq.com/openai/v1/chat/completions';

    public function __construct(
        protected KreditSimulationService $kredit,
        protected CabangService $cabangService,
    ) {
        $this->apiKey = config('services.groq.key');
        $this->model  = config('services.groq.model');
    }

    protected function tools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'hitungSimulasiKredit',
                    'description' => 'Menghitung simulasi angsuran bulanan kredit metode flat rate. Suku bunga selalu tetap sesuai kebijakan kantor (tidak perlu diisi).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'pinjaman' => ['type' => 'number', 'description' => 'Nominal pinjaman dalam Rupiah'],
                            'tenor'    => ['type' => 'number', 'description' => 'Jangka waktu kredit dalam bulan'],
                        ],
                        'required' => ['pinjaman', 'tenor'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'cariCabangTerdekat',
                    'description' => 'Mencari kantor cabang BPR Danafast terdekat dari lokasi nasabah',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'rekomendasikanProduk',
                    'description' => 'Dipanggil setiap kali kamu memberi rekomendasi produk kredit secara proaktif berdasarkan tujuan penggunaan dana nasabah',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'produk' => [
                                'type' => 'string',
                                'enum' => ['Kredit Modal Kerja', 'Kredit Investasi', 'Kredit Konsumtif', 'Kredit Multiguna'],
                            ],
                            'alasan' => ['type' => 'string', 'description' => 'Alasan singkat produk ini cocok'],
                        ],
                        'required' => ['produk', 'alasan'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'tampilkanPilihanTenor',
                    'description' => 'Menampilkan pilihan tenor umum (dalam tombol) kepada nasabah ketika nominal pinjaman sudah diketahui tapi tenor belum disebutkan, alih-alih menanyakannya lewat teks bebas',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                    ],
                ],
            ],
        ];
    }

    public function send(array $history, string $userMessage, ?array $userLocation = null, ?array $knownSlots = null): array
    {
        $messages = $this->historyToMessages($history);

        if (!empty($knownSlots)) {
            $messages[] = [
                'role' => 'user',
                'content' => '[KONTEKS SISTEM - jangan tampilkan ke nasabah] Slot yang sudah diketahui: ' . json_encode($knownSlots),
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $result = $this->callApi($messages);
        $toolCall = $this->extractToolCall($result);

        $payload = null;

        if ($toolCall) {
            $args = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];

            [$funcResult, $payload] = $this->handleFunctionCall($toolCall['function']['name'], $args, $userLocation);

            $messages[] = [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [$toolCall],
            ];
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCall['id'],
                'content' => json_encode($funcResult),
            ];

            $result = $this->callApi($messages);
        }

        $text = $this->extractText($result);

        return [
            'text' => $text ?? 'Maaf, terjadi kendala teknis. Silakan coba beberapa saat lagi.',
            'payload' => $payload,
        ];
    }

    protected function handleFunctionCall(string $name, array $args, ?array $userLocation): array
    {
        return match ($name) {
            'hitungSimulasiKredit'  => $this->handleSimulasi($args),
            'cariCabangTerdekat'    => $this->handleCabang($userLocation),
            'rekomendasikanProduk'  => $this->handleRekomendasi($args),
            'tampilkanPilihanTenor' => $this->handleTenorOptions(),
            default => [['error' => 'Fungsi tidak dikenal'], null],
        };
    }

    protected function callApi(array $messages): array
    {
        $systemMessage = ['role' => 'system', 'content' => $this->systemInstruction()];

        $response = Http::withToken($this->apiKey)
            ->timeout(30)
            ->post($this->baseUrl, [
                'model' => $this->model,
                'messages' => array_merge([$systemMessage], $messages),
                'tools' => $this->tools(),
                'temperature' => 0.4,
            ]);

        if ($response->status() === 429) {
            Log::warning('Groq rate limit tercapai');
            throw new AiRateLimitException('Groq rate limit exceeded');
        }

        if ($response->failed()) {
            Log::error('Groq API error', [
                'body' => $response->body(),
                'request_messages' => $messages,
            ]);
            return [];
        }

        return $response->json();
    }

    protected function extractToolCall(array $result): ?array
    {
        $toolCalls = $result['choices'][0]['message']['tool_calls'] ?? null;
        return $toolCalls[0] ?? null;
    }

    protected function extractText(array $result): ?string
    {
        return $result['choices'][0]['message']['content'] ?? null;
    }

    protected function historyToMessages(array $history): array
    {
        return collect($history)->take(-20)->map(fn ($h) => [
            'role' => $h['role'] === 'model' ? 'assistant' : 'user',
            'content' => $h['message'],
        ])->values()->toArray();
    }
}