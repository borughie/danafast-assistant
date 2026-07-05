# Danafast Assistant

Chatbot berbasis AI (Google Gemini API + Groq fallback) untuk membantu nasabah **BPR Danafast** berkonsultasi seputar produk kredit dan melakukan simulasi angsuran secara mandiri, kapan saja, tanpa perlu datang ke kantor cabang terlebih dahulu.

Dibangun sebagai **Final Project** Pelatihan *LLM-Based Tools and Gemini API Integration for Data Scientists*.

---

## Use Case

Danafast Assistant menggabungkan dua use case sekaligus dari kategori yang direkomendasikan:

- **Customer Service Bot** - menjawab pertanyaan seputar produk kredit BPR Danafast (jenis produk, cara pengajuan, lokasi cabang) dengan gaya bahasa sopan dan hangat.
- **Personal Productivity Assistant** - membantu nasabah menghitung simulasi angsuran kredit secara instan, tanpa perlu kalkulator manual atau datang ke cabang.

Chatbot ini **tidak** menjawab pertanyaan di luar topik produk/kredit BPR Danafast - ini sengaja dibatasi (guardrail) demi menjaga akurasi dan mencegah bot memberi informasi yang tidak resmi.

---

## Parameter Kreatif yang Diimplementasikan

| Parameter | Implementasi |
|---|---|
| **Gaya bahasa** | Bahasa Indonesia formal-hangat, dikontrol lewat system instruction yang eksplisit melarang bot keluar topik atau mengarang kebijakan resmi. |
| **Domain knowledge** | Dibatasi ketat ke 4 produk kredit BPR Danafast (Modal Kerja, Investasi, Konsumtif, Multiguna) dengan kebijakan suku bunga tetap (15% flat/tahun) yang **dipaksa dari konfigurasi server**, bukan dari jawaban model, sehingga tidak bisa "berubah-ubah" akibat halusinasi LLM. |
| **Integrasi API eksternal** | Pencarian kantor cabang terdekat menggunakan **geolocation browser** + perhitungan jarak (Haversine) + peta interaktif **Leaflet/OpenStreetMap**. |
| **Memory** | Riwayat percakapan disimpan per-guest (cookie, tanpa perlu login) dan tetap ada saat nasabah membuka kembali halaman, selama sesi belum melewati batas idle. |
| **Rekomendasi proaktif** | Begitu nasabah menyebutkan **tujuan** penggunaan dana (mis. "modal usaha", "renovasi rumah"), bot otomatis merekomendasikan satu produk kredit paling sesuai lengkap dengan alasannya - tanpa perlu ditanya. |

---

## Fitur Utama

### 1. Percakapan Natural dengan Dual AI Provider (Gemini + Groq Fallback)
Chatbot menggunakan **Gemini API** (`gemini-2.5-flash`) sebagai provider utama dengan **Groq** (`llama-3.3-70b-versatile`) sebagai fallback otomatis jika Gemini mengalami rate limit. Keduanya menggunakan **function calling** - setiap kali nasabah butuh data pasti (simulasi angsuran, lokasi cabang), model **wajib** memanggil fungsi yang sudah didefinisikan, bukan mengarang jawaban sendiri. Provider yang digunakan ditampilkan di badge kecil pada setiap balasan bot.

### 2. Simulasi Angsuran Kredit (Function Calling)
- Nasabah cukup menyebutkan nominal pinjaman & tenor secara natural (mis. *"mau pinjam 20 juta, tenor 24 bulan"*).
- Bot memanggil fungsi `hitungSimulasiKredit` untuk menghitung cicilan bulanan, total bunga, dan total pembayaran menggunakan metode **flat rate**.
- Suku bunga **selalu 15%/tahun**, dikunci dari sisi server (`config('services.danafast.suku_bunga_flat')`), sehingga konsisten di semua cabang dan tidak bisa "digoyang" oleh jawaban model.
- Jika tenor belum disebutkan, bot menampilkan **tombol pilihan tenor** (6/12/24/36/48 bulan) alih-alih bertele-tele lewat teks.

### 3. Rekomendasi Produk Proaktif + Quick Reply
- Saat nasabah menyebut tujuan dana (modal usaha, pendidikan, renovasi, dll), bot **otomatis** merekomendasikan satu produk paling sesuai beserta alasannya.
- Rekomendasi ditampilkan bersama **tombol cepat** ("Ya, hitung simulasi" / "Produk lain") agar nasabah tidak perlu mengetik ulang - cukup satu klik.

### 4. Pencarian Kantor Cabang Terdekat (Peta Interaktif)
- Browser meminta izin lokasi nasabah secara otomatis saat halaman dibuka.
- Saat nasabah bertanya lokasi/cabang terdekat, bot menghitung jarak menggunakan formula **Haversine** dan menampilkan **peta interaktif Leaflet** langsung di dalam bubble chat, lengkap dengan tautan "Buka di Google Maps".
- Jika nasabah menolak izin lokasi, sistem tetap menampilkan kantor default tanpa error.

### 5. Ekspor Simulasi ke PDF
- Setiap hasil simulasi dilengkapi tombol **"Unduh Simulasi (PDF)"**.
- PDF berisi ringkasan simulasi, jadwal angsuran **lengkap per bulan** (bukan cuplikan), serta catatan resmi bahwa hasil ini adalah simulasi dan dapat berbeda dari keputusan kredit final.
- Diproteksi per-sesi: nasabah hanya bisa mengunduh simulasi miliknya sendiri (dicek lewat cookie sesi).

### 6. Riwayat Percakapan Persisten (Tanpa Login)
- Setiap nasabah dikenali lewat cookie unik (`guest_id`).
- Percakapan tersimpan di database dan tetap muncul saat nasabah refresh atau membuka kembali halaman, selama masih dalam sesi aktif.

### 7. Edit & Retry Pesan
- Nasabah dapat **mengedit pesan** yang sudah dikirim (pesan lama dihapus, user mengetik ulang).
- Nasabah dapat **mengirim ulang** (retry) pesan terakhir tanpa mengetik ulang - balasan lama dihapus dan bot menghasilkan balasan baru.

### 8. Auto-Cleanup Sesi Tidak Aktif
- Sesi yang tidak ada aktivitas selama **15 menit** akan otomatis dianggap berakhir dan riwayatnya dihapus - baik lewat scheduled command (`chat:prune-inactive`, berjalan tiap 5 menit) maupun pengecekan saat halaman dibuka kembali.
- Ini menjaga database tidak menumpuk data percakapan basi dari pengunjung yang tidak kembali.

### 9. Rate Limiting
- Maksimal 10 pesan per 60 detik per sesi, untuk mencegah spam yang bisa membengkakkan biaya panggilan API Gemini/Groq.

### 10. UX Tambahan
- Indikator "Danafast Assistant sedang mengetik..." saat menunggu respons AI.
- Auto-scroll ke pesan terbaru.
- Auto-fokus kembali ke kolom input setelah bot selesai membalas, tanpa perlu klik manual.
- Dark mode toggle.
- Output Markdown dari model dirender rapi (list, bold, heading) dan disanitasi (anti-XSS) melalui HTMLPurifier sebelum ditampilkan.
- Badge provider (Gemini/Groq) pada setiap balasan bot.

---

## Tech Stack

- **Laravel 13** - backend & routing
- **Livewire 4** (Volt anonymous class, single-file component) - reaktivitas UI tanpa reload
- **Alpine.js** - interaksi frontend ringan (geolocation, idle timer, auto-scroll, auto-focus)
- **Tailwind CSS v4 + FluxUI** - styling & komponen UI
- **Google Gemini API** (`gemini-2.5-flash`) - model AI utama dengan dukungan *function calling*
- **Groq API** (`llama-3.3-70b-versatile`) - model AI fallback (OpenAI-compatible) jika Gemini rate limit
- **Leaflet.js + OpenStreetMap** - peta interaktif tanpa API key berbayar
- **barryvdh/laravel-dompdf** - generate PDF simulasi kredit
- **HTMLPurifier** (`clean()`) - sanitasi output Markdown dari AI

---

## Arsitektur Singkat

```
User (browser)
   |
   v
Livewire Volt Component (home.blade.php)
   |  - Menyimpan pesan user (ChatHistory)
   |  - Menampilkan indikator "mengetik"
   v
AiChatOrchestrator
   |  - Coba GeminiChatService (provider utama)
   |  - Jika rate limit -> fallback ke GroqChatService (5 menit cooldown)
   v
GeminiChatService / GroqChatService
   |  - Kirim histori + pesan ke API
   |  - Sisipkan system instruction (aturan bisnis & guardrail)
   |  - Jika model minta function call:
   |      |-- hitungSimulasiKredit      -> KreditSimulationService
   |      |-- cariCabangTerdekat        -> CabangService (Haversine)
   |      |-- rekomendasikanProduk      -> trigger quick reply chip
   |      +-- tampilkanPilihanTenor     -> trigger tenor chip
   |  - Kirim balik hasil function ke API -> dapat jawaban final
   v
Payload terstruktur (type + data) disimpan ke ChatHistory
   |
   v
Blade merender bubble chat + komponen tambahan (peta, tombol PDF, chip tenor/produk)
```

Prinsip desain utama: **model AI tidak pernah dipercaya untuk menghitung angka atau menyebutkan fakta bisnis (suku bunga, alamat cabang) secara mandiri** - semua angka dan fakta krusial selalu melalui *function calling* ke service PHP yang deterministik, sehingga hasilnya konsisten dan bisa diaudit.

---

## Instalasi & Menjalankan Secara Lokal

```bash
git clone <url-repo-ini>
cd <nama-folder>

composer install
npm install && npm run build

cp .env.example .env
php artisan key:generate

# Isi kredensial database & AI API keys di .env
# GEMINI_API_KEY=xxxxx
# GEMINI_MODEL=gemini-2.5-flash
# GROQ_API_KEY=xxxxx          # opsional, sebagai fallback
# GROQ_MODEL=llama-3.3-70b-versatile

php artisan migrate

php artisan serve
```

Pastikan juga menjadwalkan Laravel Scheduler (untuk auto-cleanup sesi tidak aktif):

```bash
# crontab server
* * * * * cd /path-ke-project && php artisan schedule:run >> /dev/null 2>&1
```

---

## Screenshot

*(tempel screenshot antarmuka chatbot kamu di sini - percakapan biasa, rekomendasi produk dengan quick reply, hasil simulasi dengan tombol unduh PDF, dan peta lokasi cabang)*

---

## Disclaimer

Seluruh hasil simulasi angsuran yang dihasilkan bot bersifat **estimasi** dan bukan merupakan penawaran resmi. Angka final tetap mengikuti kebijakan dan hasil analisis kelayakan kredit dari kantor cabang BPR Danafast.
