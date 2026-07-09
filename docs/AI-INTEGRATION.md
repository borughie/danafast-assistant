# AI Integration Reference — Danafast Assistant

Panduan lengkap pola integrasi AI (Gemini + Groq) yang digunakan pada proyek Danafast Assistant. Dapat dijadikan referensi untuk proyek Laravel lainnya yang membutuhkan chatbot AI dengan function calling.

---

## Daftar Isi

1. [Arsitektur Umum](#1-arsitektur-umum)
2. [Flow Percakapan](#2-flow-percakapan)
3. [Dual Provider + Fallback](#3-dual-provider--fallback)
4. [Function Calling (Tool Use)](#4-function-calling-tool-use)
5. [System Instruction](#5-system-instruction)
6. [Service Layer Breakdown](#6-service-layer-breakdown)
7. [Struktur Database](#7-struktur-database)
8. [Rate Limiting & Cooldown](#8-rate-limiting--cooldown)
9. [Known Slots (Context Passing)](#9-known-slots-context-passing)
10. [Template Function](#10-template-function)
11. [Cara Menggunakan di Proyek Lain](#11-cara-menggunakan-di-proyek-lain)
12. [File Reference](#12-file-reference)

---

## 1. Arsitektur Umum

```
User (browser)
   │
   ▼
Livewire Component
   │  Kirim pesan user
   ▼
AiChatOrchestrator          ← koordinator, handle fallback
   │
   ├──► GeminiChatService   ← provider utama
   │       │
   │       ├── systemInstruction()   ← aturan bisnis & guardrail
   │       ├── tools()               ← function declarations
   │       ├── callApi()             ← HTTP ke Gemini API
   │       └── handleFunctionCall()  ← eksekusi fungsi yang dipanggil model
   │              ├── hitungSimulasiKredit  → KreditSimulationService
   │              ├── cariCabangTerdekat    → CabangService
   │              ├── rekomendasikanProduk  → return data produk
   │              └── tampilkanPilihanTenor → return opsi tenor
   │
   └──► GroqChatService     ← fallback saat Gemini rate limit
           (struktur sama dengan Gemini, tapi pakai OpenAI-compatible format)
```

---

## 2. Flow Percakapan

### Alur Normal (tanpa function call)

```
1. Kirim histori + pesan user ke API
2. Model menghasilkan text → return langsung
```

### Alur dengan Function Call

```
1. Kirim histori + pesan user ke API
2. Model minta function call (misal hitungSimulasiKredit)
3. Eksekusi fungsi di PHP (deterministik)
4. Kirim hasil function ke API sebagai functionResponse
5. Model menghasilkan text berdasarkan hasil function → return
```

### Code Flow (Gemini)

```php
// 1. Kirim ke API
$result = $this->callApi($contents);

// 2. Cek apakah model minta function call
$functionCall = $this->extractFunctionCall($result);

if ($functionCall) {
    // 3. Eksekusi fungsi di PHP
    [$funcResult, $payload] = $this->handleFunctionCall($functionCall, $userLocation);

    // 4. Kirim hasil ke API lagi
    $contents[] = ['role' => 'model', 'parts' => [['functionCall' => $functionCall]]];
    $contents[] = ['role' => 'user', 'parts' => [['functionResponse' => [
        'name' => $functionCall['name'],
        'response' => $funcResult,
    ]]];

    $result = $this->callApi($contents);
}

// 5. Extract text final
$text = $this->extractText($result);
```

---

## 3. Dual Provider + Fallback

### Strategi

```php
class AiChatOrchestrator
{
    protected int $cooldownMinutes = 5;

    public function send(...): array
    {
        // Coba Gemini dulu
        if (!Cache::has('gemini:rate-limited')) {
            try {
                return $this->gemini->send(...);
            } catch (AiRateLimitException) {
                Cache::put('gemini:rate-limited', true, now()->addMinutes(5));
            }
        }

        // Fallback ke Groq
        if (!Cache::has('groq:rate-limited')) {
            try {
                return $this->groq->send(...);
            } catch (AiRateLimitException) {
                Cache::put('groq:rate-limited', true, now()->addMinutes(5));
            }
        }

        // Keduanya limit
        return ['text' => 'Layanan sedang sibuk...', 'payload' => null, 'provider' => null];
    }
}
```

### Kapan Fallback Terjadi

| Kondisi | Aksi |
|---------|------|
| Gemini normal | Pakai Gemini |
| Gemini 429 (rate limit) | Cache `gemini:rate-limited` 5 menit, fallback ke Groq |
| Gemini error lain | Fallback ke Groq (tanpa cache) |
| Groq juga 429 | Cache `groq:rate-limited`, return pesan error |
| Keduanya limit | Return pesan error ke user |

---

## 4. Function Calling (Tool Use)

### 4 Fungsi yang Didefinisikan

| Fungsi | Tujuan | Return ke UI |
|--------|--------|-------------|
| `hitungSimulasiKredit` | Hitung cicilan flat rate | `type: simulasi` → tombol PDF download |
| `cariCabangTerdekat` | Cari kantor terdekat via Haversine | `type: location` → peta Leaflet |
| `rekomendasikanProduk` | Rekomendasi produk proaktif | `type: quick_reply` → tombol aksi |
| `tampilkanPilihanTenor` | Tampilkan opsi tenor | `type: tenor_options` → tombol tenor |

### Gemini Format

```php
// tools() return:
[[ 'function_declarations' => [
    [
        'name' => 'hitungSimulasiKredit',
        'description' => 'Menghitung simulasi angsuran...',
        'parameters' => [
            'type' => 'OBJECT',
            'properties' => [
                'pinjaman' => ['type' => 'NUMBER', 'description' => '...'],
                'tenor'    => ['type' => 'NUMBER', 'description' => '...'],
            ],
            'required' => ['pinjaman', 'tenor'],
        ],
    ],
    // ... fungsi lainnya
]]];
```

### Groq/OpenAI Format

```php
// tools() return:
[
    [
        'type' => 'function',
        'function' => [
            'name' => 'hitungSimulasiKredit',
            'description' => 'Menghitung simulasi angsuran...',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'pinjaman' => ['type' => 'number', 'description' => '...'],
                    'tenor'    => ['type' => 'number', 'description' => '...'],
                ],
                'required' => ['pinjaman', 'tenor'],
            ],
        ],
    ],
    // ... fungsi lainnya
];
```

### Forced Tool Calling (untuk flow tertentu)

Saat produk sudah direkomendasikan tapi tenor belum dipilih, model **dipaksa** memanggil fungsi tenor/simulasi:

```php
// Gemini — pakai tool_config
if (!empty($knownSlots['produk_direkomendasikan']) && empty($knownSlots['simulasi_sudah_dihitung'])) {
    $payload['tool_config'] = [
        'function_calling_config' => [
            'mode' => 'ANY',
            'allowed_function_names' => ['tampilkanPilihanTenor', 'hitungSimulasiKredit'],
        ],
    ];
}

// Groq — pakai tool_choice
if (!empty($knownSlots['produk_direkomendasikan']) && empty($knownSlots['simulasi_sudah_dihitung'])) {
    $payload['tool_choice'] = [
        'type' => 'function',
        'function' => ['name' => 'tampilkanPilihanTenor'],
    ];
}
```

---

## 5. System Instruction

System instruction mendefinisikan **identitas, aturan bisnis, dan guardrail** model. Disimpan di trait `HandlesKreditFunctions` supaya bisa dipakai oleh semua provider.

### Struktur System Instruction

```
1. Identitas    → "Kamu adalah Dana Assistant, asisten virtual BPR Danafast"
2. Batasan      → "Tugasmu HANYA membahas produk kredit BPR Danafast"
3. Aturan angka → "Suku bunga flat 15%/tahun, KEBIJAKAN TETAP, jangan ubah"
4. Aturan produk → 4 produk yang tersedia
5. Guardrail    → "Jangan mengarang kebijakan, arahkan ke kantor cabang"
6. Function rules → Kapan harus memanggil fungsi apa
7. Rekomendasi  → Pemetaan tujuan penggunaan dana ke produk
```

### Poin Penting dalam System Instruction

- **Jangan pernah menghitung manual** — selalu via function call
- **Suku bunga dikunci dari config** (`config('services.danafast.suku_bunga_flat')`), bukan dari teks model
- **Guardrail topik** — jika di luar topik, arahkan kembali dengan sopan
- **Rekomendasi proaktif** — wajib rekomendasikan produk saat user menyebutkan tujuan dana
- **Jangan ulang rekomendasi** — cek histori sebelum rekomendasi lagi
- **Tenor via tombol** — jangan tanya tenor lewat teks, tampilkan sebagai tombol

---

## 6. Service Layer Breakdown

### AiChatOrchestrator

| Metode | Tanggung Jawab |
|--------|---------------|
| `send()` | Coba Gemini → fallback Groq → return error |

### GeminiChatService

| Metode | Tanggung Jawab |
|--------|---------------|
| `send()` | Orchestrasi: kirim → handle function call → kirim lagi → return |
| `tools()` | Deklarasi function Gemini format |
| `callApi()` | HTTP POST ke Gemini API |
| `extractFunctionCall()` | Ambil function call dari response |
| `extractText()` | Ambil text dari response |
| `historyToContents()` | Konversi histori ke Gemini format |
| `handleFunctionCall()` | Routing ke handler yang sesuai |

### GroqChatService

| Metode | Tanggung Jawab |
|--------|---------------|
| `send()` | Sama seperti Gemini tapi OpenAI format |
| `tools()` | Deklarasi function OpenAI format |
| `callApi()` | HTTP POST ke Groq API (Bearer token) |
| `extractToolCall()` | Ambil tool call dari response |
| `extractText()` | Ambil text dari response |
| `historyToMessages()` | Konversi histori ke OpenAI format |

### HandlesKreditFunctions (Trait)

| Metode | Tanggung Jawab |
|--------|---------------|
| `systemInstruction()` | Teks aturan bisnis untuk AI |
| `handleSimulasi()` | Hitung cicilan → return data simulasi |
| `handleCabang()` | Cari cabang terdekat → return data lokasi |
| `handleRekomendasi()` | Return data rekomendasi produk |
| `handleTenorOptions()` | Return opsi tenor [6, 12, 24, 36, 48] |
| `ensureTenorPayloadFallback()` | Fallback jika model lupa panggil tenor function |

### KreditSimulationService

| Metode | Tanggung Jawab |
|--------|---------------|
| `simulasi()` | Hitung cicilan flat rate, generate jadwal lengkap |

### CabangService

| Metode | Tanggung Jawab |
|--------|---------------|
| `semua()` | Return semua cabang |
| `terdekat()` | Cari cabang terdekat via Haversine |
| `haversine()` | Hitung jarak antar koordinat |

---

## 7. Struktur Database

### Table: `chat_histories`

```
id            BIGINT (PK, auto-increment)
guest_id      UUID (indexed)
role          ENUM('user', 'model')
message       LONGTEXT
payload       JSON (nullable)
provider      STRING (nullable) — 'gemini' atau 'groq'
created_at    TIMESTAMP
updated_at    TIMESTAMP
```

### Payload Types

| Type | Data | UI Component |
|------|------|-------------|
| `simulasi` | pinjaman, tenor, cicilan, total, jadwal | Tombol PDF download |
| `location` | nama, alamat, lat, lng, telepon, jam | Peta Leaflet + Google Maps link |
| `quick_reply` | produk, alasan | Tombol "Ya, hitung simulasi" / "Produk lain" |
| `tenor_options` | opsi: [6, 12, 24, 36, 48] | Tombol tenor |

---

## 8. Rate Limiting & Cooldown

### User → Chat (RateLimiter Laravel)

```php
// 10 pesan per 60 detik per guest
$key = "chat-send:{$this->guestId}";
if (RateLimiter::tooManyAttempts($key, 10)) {
    $seconds = RateLimiter::availableIn($key);
    // tampilkan pesan tunggu
}
RateLimiter::hit($key, 60);
```

### Provider → API (Cache-based Fallback)

```php
// Gemini rate limit → cache 5 menit → fallback Groq
Cache::put('gemini:rate-limited', true, now()->addMinutes(5));

// Groq rate limit → cache 5 menit → return error
Cache::put('groq:rate-limited', true, now()->addMinutes(5));
```

---

## 9. Known Slots (Context Passing)

Mengirim konteks tambahan ke model agar tidak mengulang hal yang sama:

```php
$knownSlots = [
    'produk_direkomendasikan' => 'Kredit Modal Kerja',  // produk sudah direkomendasikan
    'simulasi_sudah_dihitung' => true,                   // simulasi sudah dihitung
];

// Dikirim sebagai pesan tersembunyi ke model
$contents[] = [
    'role' => 'user',
    'parts' => [['text' => '[KONTEKS SISTEM - jangan tampilkan ke nasabah] Slot yang sudah diketahui: ' . json_encode($knownSlots)]],
];
```

### Kapan Dipakai

| Kondisi | Efek |
|---------|------|
| `produk_direkomendasikan` ada + `simulasi_sudah_dihitung` false | Force model panggil `tampilkanPilihanTenor` atau `hitungSimulasiKredit` |
| `simulasi_sudah_dihitung` true | Model tidak perlu hitung ulang |

---

## 10. Template Function

Pattern untuk membuat fungsi baru yang bisa dipanggil AI:

### Step 1: Deklarasi di `tools()`

```php
// Gemini format
[
    'name' => 'fungsiBaru',
    'description' => 'Deskripsi kapan fungsi ini harus dipanggil',
    'parameters' => [
        'type' => 'OBJECT',
        'properties' => [
            'param1' => ['type' => 'STRING', 'description' => '...'],
        ],
        'required' => ['param1'],
    ],
]
```

### Step 2: Handler di Trait

```php
protected function handleFungsiBaru(array $args): array
{
    $result = // logika bisnis deterministik
    return [$result, ['type' => 'ui_type', 'data' => $result]];
}
```

### Step 3: Routing di `handleFunctionCall()`

```php
protected function handleFunctionCall(array $functionCall, ?array $userLocation): array
{
    return match ($functionCall['name']) {
        'fungsiBaru' => $this->handleFungsiBaru($functionCall['args']),
        // ... fungsi lainnya
    };
}
```

### Step 4: UI Handler di Blade

```blade
@if ($type === 'ui_type')
    <div>/* render data */</div>
@endif
```

---

## 11. Cara Menggunakan di Proyek Lain

### 1. Buat Service Pattern

```
app/Services/
├── AiChatOrchestrator.php          ← fallback coordinator
├── GeminiChatService.php           ← atau OpenAI, Anthropic, dll
├── GroqChatService.php             ← fallback provider (opsional)
├── Concerns/
│   └── HandlesYourFunctions.php    ← shared trait untuk semua provider
└── YourDomainServices.php          ← service bisnis (simulasi, lokasi, dll)
```

### 2. Config Services

```php
// config/services.php
'gemini' => [
    'key' => env('GEMINI_API_KEY'),
    'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
],
```

### 3. System Instruction Pattern

```php
protected function systemInstruction(): string
{
    return <<<TEXT
    Kamu adalah [NAMA BOT], asisten virtual untuk [DOMAIN BISNIS].
    Tugasmu HANYA membahas [TOPIK].

    Aturan:
    - [Aturan bisnis 1]
    - [Aturan bisnis 2]
    - Jika di luar topik, arahkan kembali dengan sopan.
    - Jangan pernah mengarang data, selalu gunakan function call.
    TEXT;
}
```

### 4. Env Variables

```env
GEMINI_API_KEY=your-key-here
GEMINI_MODEL=gemini-2.5-flash
GROQ_API_KEY=your-key-here        # optional fallback
GROQ_MODEL=llama-3.3-70b-versatile
```

---

## 12. File Reference

| File | Lokasi | Fungsi |
|------|--------|--------|
| `AiChatOrchestrator.php` | `app/Services/` | Koordinator fallback Gemini → Groq |
| `GeminiChatService.php` | `app/Services/` | Integrasi Gemini API + function calling |
| `GroqChatService.php` | `app/Services/` | Integrasi Groq API (OpenAI-compatible) |
| `HandlesKreditFunctions.php` | `app/Services/Concerns/` | System instruction + handler fungsi |
| `KreditSimulationService.php` | `app/Services/` | Kalkulasi cicilan flat rate |
| `CabangService.php` | `app/Services/` | Data cabang + Haversine distance |
| `AiRateLimitException.php` | `app/Exceptions/` | Exception khusus rate limit |
| `services.php` | `config/` | Konfigurasi API key + model |
| `home.blade.php` | `resources/views/pages/` | UI chat + rendering payload |
