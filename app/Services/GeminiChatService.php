<?php

namespace App\Services;

use App\Services\Concerns\HandlesKreditFunctions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Exceptions\AiRateLimitException;

class GeminiChatService
{
    use HandlesKreditFunctions;

    protected string $apiKey;
    protected string $model;
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct(
        protected KreditSimulationService $kredit,
        protected CabangService $cabangService,
    ) {
        $this->apiKey = config('services.gemini.key');
        $this->model  = config('services.gemini.model');
    }

    protected function tools(): array
    {
        return [[
            'function_declarations' => [
                [
                    'name' => 'hitungSimulasiKredit',
                    'description' => 'Menghitung simulasi angsuran bulanan kredit metode flat rate. Suku bunga selalu tetap sesuai kebijakan kantor (tidak perlu diisi).',
                    'parameters' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'pinjaman' => ['type' => 'NUMBER', 'description' => 'Nominal pinjaman dalam Rupiah'],
                            'tenor'    => ['type' => 'NUMBER', 'description' => 'Jangka waktu kredit dalam bulan'],
                        ],
                        'required' => ['pinjaman', 'tenor'],
                    ],
                ],
                [
                    'name' => 'cariCabangTerdekat',
                    'description' => 'Mencari kantor cabang BPR Danafast terdekat dari lokasi nasabah',
                    'parameters' => [
                        'type' => 'OBJECT',
                        'properties' => new \stdClass(),
                    ],
                ],
                [
                    'name' => 'rekomendasikanProduk',
                    'description' => 'Dipanggil setiap kali kamu memberi rekomendasi produk kredit secara proaktif berdasarkan tujuan penggunaan dana nasabah',
                    'parameters' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'produk' => [
                                'type' => 'STRING',
                                'enum' => ['Kredit Modal Kerja', 'Kredit Investasi', 'Kredit Konsumtif', 'Kredit Multiguna'],
                            ],
                            'alasan' => ['type' => 'STRING', 'description' => 'Alasan singkat produk ini cocok'],
                        ],
                        'required' => ['produk', 'alasan'],
                    ],
                ],
                [
                    'name' => 'tampilkanPilihanTenor',
                    'description' => 'Menampilkan pilihan tenor umum (dalam tombol) kepada nasabah ketika nominal pinjaman sudah diketahui tapi tenor belum disebutkan, alih-alih menanyakannya lewat teks bebas',
                    'parameters' => [
                        'type' => 'OBJECT',
                        'properties' => new \stdClass(),
                    ],
                ],
            ],
        ]];
    }

    public function send(array $history, string $userMessage, ?array $userLocation = null, ?array $knownSlots = null): array
    {
        $contents = $this->historyToContents($history);
        if (!empty($knownSlots)) {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => '[KONTEKS SISTEM - jangan tampilkan ke nasabah] Slot yang sudah diketahui: ' . json_encode($knownSlots)]],
            ];
        }

        $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

        $result = $this->callApi($contents);
        $functionCall = $this->extractFunctionCall($result);

        $payload = null;

        if ($functionCall) {
            if (empty($functionCall['args'])) {
                $functionCall['args'] = new \stdClass();
            }

            [$funcResult, $payload] = $this->handleFunctionCall($functionCall, $userLocation);

            $contents[] = ['role' => 'model', 'parts' => [['functionCall' => $functionCall]]];
            $contents[] = [
                'role' => 'user',
                'parts' => [[
                    'functionResponse' => [
                        'name' => $functionCall['name'],
                        'response' => $funcResult,
                    ],
                ]],
            ];

            $result = $this->callApi($contents);

            if ($this->extractText($result) === null) {
                Log::warning('Gemini: gagal extract text setelah function response', [
                    'function' => $functionCall['name'],
                    'raw_result' => $result,
                ]);
            }
        }

        $text = $this->extractText($result);

        if ($text !== null && preg_match('/^(tool_code|thought\b|print\(default_api)/i', trim($text))) {
            Log::warning('Gemini: text output terdeteksi sebagai raw trace, bukan jawaban final', [
                'raw_text' => $text,
            ]);
            $text = null;
        }

        return [
            'text' => $text ?? 'Maaf, terjadi kendala teknis. Silakan coba beberapa saat lagi.',
            'payload' => $payload,
        ];
    }

    protected function handleFunctionCall(array $functionCall, ?array $userLocation): array
    {
        return match ($functionCall['name']) {
            'hitungSimulasiKredit'   => $this->handleSimulasi($functionCall['args']),
            'cariCabangTerdekat'     => $this->handleCabang($userLocation),
            'rekomendasikanProduk'   => $this->handleRekomendasi($functionCall['args']),
            'tampilkanPilihanTenor'  => $this->handleTenorOptions(),
            default => [['error' => 'Fungsi tidak dikenal'], null],
        };
    }

    protected function callApi(array $contents): array
    {
        $payload = [
            'system_instruction' => ['parts' => [['text' => $this->systemInstruction()]]],
            'contents' => $contents,
            'tools' => $this->tools(),
            'generationConfig' => ['temperature' => 0.4],
        ];

        $response = Http::timeout(30)->post(
            "{$this->baseUrl}/{$this->model}:generateContent?key={$this->apiKey}",
            $payload
        );

        if ($response->status() === 429) {
            Log::warning('Gemini rate limit tercapai, akan fallback ke Groq');
            throw new AiRateLimitException('Gemini rate limit exceeded');
        }

        if ($response->failed()) {
            Log::error('Gemini API error', [
                'body' => $response->body(),
                'request_contents' => $contents,
            ]);
            return [];
        }

        return $response->json();
    }

    protected function extractFunctionCall(array $result): ?array
    {
        foreach ($result['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['functionCall'])) {
                return $part['functionCall'];
            }
        }
        return null;
    }

    protected function extractText(array $result): ?string
    {
        foreach ($result['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['text'])) {
                return $part['text'];
            }
        }
        return null;
    }

    protected function historyToContents(array $history): array
    {
        return collect($history)->take(-20)->map(fn ($h) => [
            'role' => $h['role'],
            'parts' => [['text' => $h['message']]],
        ])->values()->toArray();
    }
}