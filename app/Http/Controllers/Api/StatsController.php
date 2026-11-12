<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\Item;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    /**
     * Jumlah minggu yang ditampilkan di grafik dan jendela "pengguna aktif" (hari).
     */
    private const JUMLAH_MINGGU = 8;
    private const HARI_PENGGUNA_AKTIF = 30;

    /**
     * Lama cache hasil statistik (detik), agar endpoint publik tidak
     * menghitung ulang tiap request.
     */
    private const DETIK_CACHE = 60;

    /**
     * Data dashboard dampak. Rute ini publik karena dipakai landing page.
     */
    public function dampak(): JsonResponse
    {
        $data = Cache::remember('stats.impact', self::DETIK_CACHE, fn () => $this->hitung());

        return response()->json([
            'server_time' => now()->toIso8601String(),
        ] + $data);
    }

    private function hitung(): array
    {
        // Dasar perhitungan adalah klaim yang selesai. Memakai join langsung
        // (bukan relasi Eloquent) agar item yang sudah di-soft delete tetap terhitung.
        $perJenis = Claim::query()
            ->where('claims.status', Claim::STATUS_COMPLETED)
            ->join('items', 'items.id', '=', 'claims.item_id')
            ->select(
                'items.jenis',
                DB::raw('COUNT(*) as jumlah_klaim'),
                DB::raw('COALESCE(SUM(items.jumlah), 0) as jumlah_unit')
            )
            ->groupBy('items.jenis')
            ->get()
            ->keyBy('jenis');

        $ambil = function (string $jenis) use ($perJenis): array {
            $baris = $perJenis->get($jenis);

            return [
                'klaim_selesai' => (int) ($baris->jumlah_klaim ?? 0),
                'jumlah_unit' => (int) ($baris->jumlah_unit ?? 0),
            ];
        };

        $makanan = $ambil(Item::JENIS_MAKANAN);
        $pakaian = $ambil(Item::JENIS_PAKAIAN);
        $barang = $ambil(Item::JENIS_BARANG);

        return [
            'dampak' => [
                'total_klaim_selesai' => $makanan['klaim_selesai'] + $pakaian['klaim_selesai'] + $barang['klaim_selesai'],
                'total_unit' => $makanan['jumlah_unit'] + $pakaian['jumlah_unit'] + $barang['jumlah_unit'],
                'makanan' => $makanan,
                'pakaian' => $pakaian,
                'barang' => $barang,
            ],
            'tersedia_sekarang' => Item::tampil()->count(),
            'pengguna_terdaftar' => User::count(),
            'pengguna_aktif_30_hari' => $this->penggunaAktif(),
            'mingguan' => $this->mingguan(),
        ];
    }

    /**
     * Pengguna yang memposting atau mengklaim dalam 30 hari terakhir.
     */
    private function penggunaAktif(): int
    {
        $batas = now()->subDays(self::HARI_PENGGUNA_AKTIF);

        $pemberi = Item::withTrashed()
            ->where('created_at', '>=', $batas)
            ->distinct()
            ->pluck('user_id');

        $penerima = Claim::where('created_at', '>=', $batas)
            ->distinct()
            ->pluck('user_id');

        return $pemberi->merge($penerima)->unique()->count();
    }

    /**
     * Klaim selesai per minggu untuk beberapa minggu terakhir.
     * Minggu tanpa data tetap ditampilkan dengan nilai 0 supaya grafik tidak bolong.
     * Awal minggu mengikuti zona waktu server (UTC).
     */
    private function mingguan(): array
    {
        $awal = now()->startOfWeek()->subWeeks(self::JUMLAH_MINGGU - 1);

        $baris = Claim::query()
            ->where('claims.status', Claim::STATUS_COMPLETED)
            ->where('claims.completed_at', '>=', $awal)
            ->join('items', 'items.id', '=', 'claims.item_id')
            ->select('claims.completed_at', 'items.jumlah')
            ->get();

        $kelompok = [];

        foreach ($baris as $b) {
            $kunci = $b->completed_at->copy()->startOfWeek()->toDateString();

            $kelompok[$kunci]['klaim_selesai'] = ($kelompok[$kunci]['klaim_selesai'] ?? 0) + 1;
            $kelompok[$kunci]['jumlah_unit'] = ($kelompok[$kunci]['jumlah_unit'] ?? 0) + (int) $b->jumlah;
        }

        $hasil = [];

        for ($i = 0; $i < self::JUMLAH_MINGGU; $i++) {
            $mulai = Carbon::parse($awal)->addWeeks($i)->toDateString();

            $hasil[] = [
                'minggu_mulai' => $mulai,
                'klaim_selesai' => $kelompok[$mulai]['klaim_selesai'] ?? 0,
                'jumlah_unit' => $kelompok[$mulai]['jumlah_unit'] ?? 0,
            ];
        }

        return $hasil;
    }
}