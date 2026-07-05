<?php

namespace App\Services;

class KreditSimulationService
{
    /**
     * Formula ini SENGAJA disamakan dengan halaman simulasi manual
     * agar angka yang keluar dari chatbot tidak pernah berbeda
     * dengan halaman kalkulator kredit yang sudah ada.
     */
    public function simulasi(float $pinjaman, int $tenor, float $sukuBunga): array
    {
        if ($pinjaman <= 0 || $tenor <= 0 || $sukuBunga <= 0) {
            return ['error' => 'Nominal pinjaman, tenor, dan suku bunga harus lebih dari 0.'];
        }

        $bungaPerBulan  = ($pinjaman * ($sukuBunga / 100)) / 12;
        $cicilanPokok   = $pinjaman / $tenor;
        $cicilanBulanan = round($cicilanPokok + $bungaPerBulan);
        $totalBayar     = $cicilanBulanan * $tenor;
        $totalBunga     = $totalBayar - $pinjaman;

        $sisa   = $pinjaman;
        $jadwal = [];
        for ($i = 1; $i <= $tenor; $i++) {
            $sisa -= $cicilanPokok;
            $jadwal[] = [
                'bulan'   => $i,
                'cicilan' => $cicilanBulanan,
                'pokok'   => round($cicilanPokok),
                'bunga'   => round($bungaPerBulan),
                'sisa'    => (int) max(0, round($sisa)),
            ];
        }

        return [
            'pinjaman'         => $pinjaman,
            'tenor_bulan'      => $tenor,
            'suku_bunga'       => $sukuBunga,
            'cicilan_bulanan'  => $cicilanBulanan,
            'total_bayar'      => $totalBayar,
            'total_bunga'      => $totalBunga,
            // jadwal lengkap tidak perlu dikirim balik ke Gemini (buang2 token),
            // cukup ringkasan 3 bulan pertama & terakhir sebagai konteks
            'contoh_jadwal'    => array_merge(
                array_slice($jadwal, 0, 3),
                $tenor > 6 ? array_slice($jadwal, -1) : []
            ),
            // lengkap, HANYA untuk PDF — tidak dikirim ke Gemini
            'jadwal_lengkap'   => $jadwal,
        ];
    }
}