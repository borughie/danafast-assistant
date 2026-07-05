<?php

namespace App\Services;

class CabangService
{
    /**
     * Data kantor cabang. Untuk saat ini baru 1 entry (kantor pusat),
     * tinggal tambah array baru kalau ada cabang lain.
     */
    protected array $cabang = [
        [
            'nama'    => 'BPR Danafast - Kantor Pusat',
            'alamat'  => 'Jl. Mulawarman No. 02 RT. 29, Kel. Karang Anyar Pantai, Kec. Tarakan Barat, Kota Tarakan - Kalimantan Utara', // sesuaikan dengan alamat resmi
            'lat'     => 3.3212497,
            'lng'     => 117.5753774,
            'telepon' => '(0551) 381 2879',
            'jam'     => 'Senin-Jumat, 08.00-16.00 WITA',
        ],
    ];

    public function semua(): array
    {
        return $this->cabang;
    }

    /**
     * Cari cabang terdekat dari koordinat user.
     * Kalau koordinat user tidak tersedia, kembalikan cabang pertama sebagai default.
     */
    public function terdekat(?float $userLat, ?float $userLng): array
    {
        if ($userLat === null || $userLng === null) {
            $default = $this->cabang[0];
            $default['jarak_km'] = null;
            return $default;
        }

        $terdekat = null;
        $jarakMin = null;

        foreach ($this->cabang as $c) {
            $jarak = $this->haversine($userLat, $userLng, $c['lat'], $c['lng']);
            if ($jarakMin === null || $jarak < $jarakMin) {
                $jarakMin = $jarak;
                $terdekat = $c;
            }
        }

        $terdekat['jarak_km'] = round($jarakMin, 1);

        return $terdekat;
    }

    protected function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}