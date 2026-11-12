<?php

/*
|--------------------------------------------------------------------------
| Uji konkurensi klaim BagiRasa
|--------------------------------------------------------------------------
| Beberapa proses PHP terpisah mengklaim SATU item pada detik yang sama.
| Hasil yang benar: tepat satu klaim berhasil, sisanya ditolak.
|
| Pemakaian:
|   php scripts/uji-konkurensi.php
|   php scripts/uji-konkurensi.php --permintaan=15 --putaran=5
|
| Data uji memakai akun berakhiran @konkurensi.test dan dihapus lagi
| setelah selesai. Data seeder dan data asli tidak disentuh.
*/

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\Claim;
use App\Models\Item;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

$basePath = dirname(__DIR__);

require $basePath . '/vendor/autoload.php';

if (($argv[1] ?? '') === '--anak') {
    jalankanAnak($basePath, $argv);
    exit(0);
}

exit(jalankanInduk($basePath, $argv));

/**
 * Proses anak: memuat Laravel, menunggu aba-aba, lalu mengirim satu permintaan klaim.
 */
function jalankanAnak(string $basePath, array $argv): void
{
    $token = (string) ($argv[2] ?? '');
    $itemId = (int) ($argv[3] ?? 0);
    $mulai = (float) ($argv[4] ?? 0);

    $app = require $basePath . '/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $kernel->bootstrap();

    // Koneksi database dibuka lebih dulu supaya waktu tembak benar-benar serempak.
    $app['db']->connection()->getPdo();

    $request = Illuminate\Http\Request::create("/api/items/{$itemId}/claim", 'POST', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
    ]);

    while (microtime(true) < $mulai) {
        usleep(50);
    }

    $awal = microtime(true);
    $response = $kernel->handle($request);
    $durasi = (int) round((microtime(true) - $awal) * 1000);

    $isi = json_decode((string) $response->getContent(), true);

    echo json_encode([
        'status' => $response->getStatusCode(),
        'pesan' => is_array($isi) ? ($isi['message'] ?? '') : '',
        'kode' => is_array($isi) ? ($isi['claim']['kode_klaim'] ?? null) : null,
        'ms' => $durasi,
    ]) . PHP_EOL;
}

/**
 * Proses induk: menyiapkan data, menembakkan proses anak, memeriksa hasil, membersihkan.
 */
function jalankanInduk(string $basePath, array $argv): int
{
    $permintaan = 10;
    $putaran = 3;

    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--permintaan=(\d+)$/', $arg, $m)) {
            $permintaan = (int) $m[1];
        }
        if (preg_match('/^--putaran=(\d+)$/', $arg, $m)) {
            $putaran = (int) $m[1];
        }
    }

    $permintaan = max(2, min(30, $permintaan));
    $putaran = max(1, min(10, $putaran));

    $app = require $basePath . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    if ($app->environment('production')) {
        fwrite(STDERR, "Dihentikan: skrip uji tidak boleh dijalankan di lingkungan production.\n");

        return 2;
    }

    echo PHP_EOL;
    echo "UJI KONKURENSI KLAIM BAGIRASA\n";
    echo str_repeat('=', 60) . PHP_EOL;
    echo "Permintaan serempak per putaran : {$permintaan}\n";
    echo "Jumlah putaran                  : {$putaran}\n";
    echo 'Database                        : ' . config('database.connections.' . config('database.default') . '.database') . "\n";

    // Sisa data dari uji yang pernah terhenti di tengah jalan.
    bersihkan();

    $semuaLulus = true;

    try {
        $pemberi = User::create([
            'name' => 'Pemberi Uji',
            'email' => 'pemberi@konkurensi.test',
            'password' => bin2hex(random_bytes(8)),
            'no_whatsapp' => '081299990000',
            'area' => 'Uji',
        ]);

        $penerima = [];
        $token = [];

        for ($i = 1; $i <= $permintaan; $i++) {
            $u = User::create([
                'name' => "Penerima Uji {$i}",
                'email' => "penerima{$i}@konkurensi.test",
                'password' => bin2hex(random_bytes(8)),
                'no_whatsapp' => '08129999' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'area' => 'Uji',
            ]);

            $penerima[$i] = $u;
            $token[$i] = $u->createToken('uji-konkurensi', ['*'], now()->addHour())->plainTextToken;
        }

        for ($r = 1; $r <= $putaran; $r++) {
            echo PHP_EOL . "PUTARAN {$r}\n" . str_repeat('-', 60) . PHP_EOL;

            $item = Item::create([
                'user_id' => $pemberi->id,
                'judul' => "Nasi Kotak Uji Putaran {$r}",
                'jenis' => Item::JENIS_MAKANAN,
                'kategori' => 'makanan_berat',
                'jumlah' => 1,
                'area' => 'Uji',
                'lokasi_detail' => 'Lokasi uji',
                'waktu_dibuat_makanan' => now()->subMinutes(10),
                'expires_at' => now()->addHours(3),
                'status' => Item::STATUS_AVAILABLE,
            ]);

            $hasil = tembakSerempak($permintaan, $token, $item->id);

            $berhasil = 0;

            foreach ($hasil as $no => $h) {
                if ($h['status'] === 201) {
                    $berhasil++;
                }

                printf(
                    "  Penerima %-2d  HTTP %-3s  %-4s ms  %s\n",
                    $no,
                    (string) $h['status'],
                    (string) $h['ms'],
                    $h['status'] === 201 ? 'BERHASIL, kode ' . $h['kode'] : mb_strimwidth((string) $h['pesan'], 0, 60, '...')
                );
            }

            $klaimAktif = Claim::where('item_id', $item->id)->where('status', Claim::STATUS_ACTIVE)->count();
            $totalKlaim = Claim::where('item_id', $item->id)->count();
            $statusItem = $item->fresh()->status;

            $lulus = $berhasil === 1
                && $klaimAktif === 1
                && $totalKlaim === 1
                && $statusItem === Item::STATUS_LOCKED
                && count($hasil) === $permintaan;

            $semuaLulus = $semuaLulus && $lulus;

            echo PHP_EOL;
            echo "  Respons 201 (berhasil)   : {$berhasil} (harus 1)\n";
            echo "  Klaim tercatat di DB     : {$totalKlaim} (harus 1)\n";
            echo "  Klaim berstatus active   : {$klaimAktif} (harus 1)\n";
            echo "  Status item              : {$statusItem} (harus locked)\n";
            echo '  Hasil putaran            : ' . ($lulus ? 'LULUS' : 'GAGAL') . PHP_EOL;

            // Menghapus item juga menghapus klaimnya, jadi putaran berikutnya mulai bersih.
            $item->forceDelete();
        }
    } finally {
        bersihkan();
    }

    echo PHP_EOL . str_repeat('=', 60) . PHP_EOL;

    if ($semuaLulus) {
        echo "KESIMPULAN: LULUS. Dari {$permintaan} permintaan serempak per putaran, hanya satu klaim yang berhasil.\n";
        echo "Tidak ada double-claim pada {$putaran} putaran.\n";

        return 0;
    }

    echo "KESIMPULAN: GAGAL. Ada putaran yang menghasilkan lebih dari satu klaim, atau hasilnya tidak lengkap.\n";
    echo "Periksa storage/logs/laravel.log dan pastikan mesin tabel items dan claims adalah InnoDB.\n";

    return 1;
}

/**
 * Menjalankan satu proses anak per penerima, semuanya menembak pada detik yang sama.
 *
 * @return array<int, array{status:int|string, pesan:string, kode:?string, ms:int|string}>
 */
function tembakSerempak(int $jumlah, array $token, int $itemId): array
{
    // Jeda cukup lama agar semua proses anak selesai memuat Laravel sebelum aba-aba.
    $mulai = microtime(true) + 4.0 + ($jumlah * 0.15);

    $proses = [];
    $pipa = [];

    for ($i = 1; $i <= $jumlah; $i++) {
        $perintah = [PHP_BINARY, __FILE__, '--anak', $token[$i], (string) $itemId, sprintf('%.6F', $mulai)];

        $p = proc_open($perintah, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipa[$i]);

        if (is_resource($p)) {
            fclose($pipa[$i][0]);
            $proses[$i] = $p;
        }
    }

    $hasil = [];

    foreach ($proses as $i => $p) {
        $keluar = stream_get_contents($pipa[$i][1]);
        stream_get_contents($pipa[$i][2]);
        fclose($pipa[$i][1]);
        fclose($pipa[$i][2]);
        proc_close($p);

        $baris = array_values(array_filter(array_map('trim', explode("\n", (string) $keluar))));
        $terakhir = $baris ? json_decode(end($baris), true) : null;

        $hasil[$i] = is_array($terakhir)
            ? $terakhir
            : ['status' => 'ERR', 'pesan' => 'Proses anak tidak memberi hasil.', 'kode' => null, 'ms' => '-'];
    }

    ksort($hasil);

    return $hasil;
}

/**
 * Menghapus semua data uji (akun berakhiran @konkurensi.test beserta isinya).
 */
function bersihkan(): void
{
    $ids = User::where('email', 'like', '%@konkurensi.test')->pluck('id');

    if ($ids->isEmpty()) {
        return;
    }

    Item::withTrashed()->whereIn('user_id', $ids)->forceDelete();
    ActivityLog::whereIn('user_id', $ids)->delete();
    PersonalAccessToken::where('tokenable_type', User::class)->whereIn('tokenable_id', $ids)->delete();
    User::whereIn('id', $ids)->delete();
}