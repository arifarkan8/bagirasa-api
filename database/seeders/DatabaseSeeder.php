<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Claim;
use App\Models\Item;
use App\Models\Notifikasi;
use App\Models\Report;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Kata sandi untuk SEMUA akun dummy. Hanya untuk pengembangan dan demo.
     */
    private const PASSWORD_DUMMY = 'password123';

    public function run(): void
    {
        $admin = $this->buatAkun('Admin BagiRasa', 'admin@bagirasa.test', '081200000000', 'Kampus Utara', 'admin');
        $siti = $this->buatAkun('Siti Katering', 'siti@bagirasa.test', '081200000001', 'Kampus Utara');
        $budi = $this->buatAkun('Budi Santoso', 'budi@bagirasa.test', '081200000002', 'Kampus Selatan');
        $dewi = $this->buatAkun('Dewi Lestari', 'dewi@bagirasa.test', '081200000003', 'Perumahan Griya Asri');
        $rizky = $this->buatAkun('Rizky Pratama', 'rizky@bagirasa.test', '081200000004', 'Pasar Pagi');

        // Hindari data ganda kalau seeder dijalankan lebih dari sekali.
        if (Item::withTrashed()->exists()) {
            $this->command?->warn('Item sudah ada, data item dan klaim dummy dilewati.');
            $this->ringkasan();

            return;
        }

        $this->itemTersedia($siti, $budi, $dewi, $rizky);
        $this->itemTerkunci($budi, $rizky);
        $this->itemKedaluwarsa($budi);
        $this->riwayatSelesai($siti, $budi, $dewi, $rizky);
        $this->laporanContoh($dewi, $rizky);

        $this->ringkasan();
    }

    private function buatAkun(string $nama, string $email, string $wa, string $area, string $role = 'user'): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $nama,
                'password' => self::PASSWORD_DUMMY,
                'no_whatsapp' => $wa,
                'area' => $area,
                'role' => $role,
                'status_akun' => 'aktif',
            ]
        );
    }

    /**
     * Item yang sedang tersedia, campuran makanan, pakaian, dan barang.
     */
    private function itemTersedia(User $siti, User $budi, User $dewi, User $rizky): void
    {
        $this->buatItem($siti, [
            'judul' => 'Nasi Kotak Ayam Bakar',
            'deskripsi' => 'Sisa konsumsi seminar, dikemas rapi dan baru dimasak.',
            'jenis' => Item::JENIS_MAKANAN,
            'kategori' => 'makanan_berat',
            'jumlah' => 25,
            'area' => 'Kampus Utara',
            'lokasi_detail' => 'Lobi Gedung A, meja resepsionis',
            'waktu_dibuat_makanan' => now()->subMinutes(45),
            'expires_at' => now()->addHours(3),
        ]);

        $this->buatItem($siti, [
            'judul' => 'Roti Manis Sisa Acara',
            'deskripsi' => 'Roti isi cokelat dan keju, kemasan masih tersegel.',
            'jenis' => Item::JENIS_MAKANAN,
            'kategori' => 'kue_roti',
            'jumlah' => 30,
            'area' => 'Kampus Utara',
            'lokasi_detail' => 'Ruang sekretariat BEM, lantai 2',
            'waktu_dibuat_makanan' => now()->subHour(),
            'expires_at' => now()->addHours(5),
        ]);

        $this->buatItem($budi, [
            'judul' => 'Es Teh Kemasan Gelas',
            'jenis' => Item::JENIS_MAKANAN,
            'kategori' => 'minuman',
            'jumlah' => 40,
            'area' => 'Kampus Selatan',
            'lokasi_detail' => 'Kantin pojok, dekat parkiran motor',
            'waktu_dibuat_makanan' => now()->subMinutes(30),
            'expires_at' => now()->addHours(5),
        ]);

        $this->buatItem($dewi, [
            'judul' => 'Mi Instan dan Biskuit',
            'deskripsi' => 'Kemasan utuh, kedaluwarsa masih lama.',
            'jenis' => Item::JENIS_MAKANAN,
            'kategori' => 'makanan_kemasan',
            'jumlah' => 15,
            'area' => 'Perumahan Griya Asri',
            'lokasi_detail' => 'Rumah nomor 12, teras depan',
            'waktu_dibuat_makanan' => now()->subHours(2),
            'expires_at' => now()->addHours(48),
        ]);

        $this->buatItem($dewi, [
            'judul' => 'Jaket dan Kemeja Layak Pakai',
            'deskripsi' => 'Sudah dicuci bersih, ukuran M sampai XL.',
            'jenis' => Item::JENIS_PAKAIAN,
            'kategori' => 'pakaian_dewasa',
            'kondisi' => 'layak_pakai',
            'jumlah' => 12,
            'area' => 'Perumahan Griya Asri',
            'lokasi_detail' => 'Rumah nomor 12, teras depan',
        ]);

        $this->buatItem($budi, [
            'judul' => 'Pakaian Anak Usia 3 sampai 7 Tahun',
            'jenis' => Item::JENIS_PAKAIAN,
            'kategori' => 'pakaian_anak',
            'kondisi' => 'layak_pakai',
            'jumlah' => 10,
            'area' => 'Kampus Selatan',
            'lokasi_detail' => 'Pos satpam gerbang selatan',
        ]);

        $this->buatItem($rizky, [
            'judul' => 'Selimut Tebal',
            'deskripsi' => 'Untuk korban banjir, bisa diambil sampai akhir pekan depan.',
            'jenis' => Item::JENIS_PAKAIAN,
            'kategori' => 'selimut_kain',
            'kondisi' => 'layak_pakai',
            'jumlah' => 6,
            'area' => 'Pasar Pagi',
            'lokasi_detail' => 'Kios nomor 7, lorong barat',
            'expires_at' => now()->addDays(10),
        ]);

        $this->buatItem($rizky, [
            'judul' => 'Rak Buku Kayu',
            'jenis' => Item::JENIS_BARANG,
            'kategori' => 'perabot',
            'kondisi' => 'layak_pakai',
            'jumlah' => 1,
            'area' => 'Pasar Pagi',
            'lokasi_detail' => 'Kios nomor 7, lorong barat',
        ]);

        $this->buatItem($dewi, [
            'judul' => 'Terpal dan Lampu Darurat',
            'deskripsi' => 'Masih baru, cocok untuk posko bantuan bencana.',
            'jenis' => Item::JENIS_BARANG,
            'kategori' => 'kebutuhan_bencana',
            'kondisi' => 'baru',
            'jumlah' => 5,
            'area' => 'Perumahan Griya Asri',
            'lokasi_detail' => 'Rumah nomor 12, garasi',
        ]);

        $this->buatItem($budi, [
            'judul' => 'Kipas Angin Meja',
            'deskripsi' => 'Putarannya agak berisik, tapi masih menyala.',
            'jenis' => Item::JENIS_BARANG,
            'kategori' => 'elektronik',
            'kondisi' => 'perlu_perbaikan',
            'jumlah' => 1,
            'area' => 'Kampus Selatan',
            'lokasi_detail' => 'Pos satpam gerbang selatan',
        ]);
    }

    /**
     * Satu item yang sedang terkunci oleh klaim aktif, lengkap dengan notifikasi.
     */
    private function itemTerkunci(User $budi, User $rizky): void
    {
        $item = $this->buatItem($budi, [
            'judul' => 'Nasi Bungkus Sayur',
            'jenis' => Item::JENIS_MAKANAN,
            'kategori' => 'makanan_berat',
            'jumlah' => 10,
            'area' => 'Kampus Selatan',
            'lokasi_detail' => 'Kantin pojok, meja paling ujung',
            'waktu_dibuat_makanan' => now()->subMinutes(40),
            'expires_at' => now()->addHours(3),
            'status' => Item::STATUS_LOCKED,
        ]);

        $klaim = Claim::create([
            'item_id' => $item->id,
            'user_id' => $rizky->id,
            'kode_klaim' => Claim::buatKode(),
            'locked_until' => now()->addMinutes(20),
            'status' => Claim::STATUS_ACTIVE,
        ]);

        Notifikasi::kirim(
            $budi->id,
            Notifikasi::TIPE_ITEM_DIKLAIM,
            'Item Anda diklaim',
            "{$rizky->name} mengklaim \"{$item->judul}\" dengan kode {$klaim->kode_klaim}.",
            $item->id,
            $klaim->id
        );

        ActivityLog::catat('klaim_dibuat', $rizky->id, 'claim', $klaim->id, ['item_id' => $item->id]);
    }

    /**
     * Satu item makanan yang sudah lewat batas kelayakan.
     */
    private function itemKedaluwarsa(User $budi): void
    {
        $this->buatItem($budi, [
            'judul' => 'Es Teh Acara Kemarin',
            'jenis' => Item::JENIS_MAKANAN,
            'kategori' => 'minuman',
            'jumlah' => 20,
            'area' => 'Kampus Selatan',
            'lokasi_detail' => 'Kantin pojok',
            'waktu_dibuat_makanan' => now()->subHours(8),
            'expires_at' => now()->subHours(2),
            'status' => Item::STATUS_EXPIRED,
        ]);
    }

    /**
     * Riwayat klaim selesai di beberapa minggu terakhir, supaya
     * dashboard dampak dan grafik mingguan langsung punya isi.
     */
    private function riwayatSelesai(User $siti, User $budi, User $dewi, User $rizky): void
    {
        $this->buatItemSelesai($siti, $dewi, now()->subWeeks(1)->setTime(10, 0), [
            'judul' => 'Nasi Kotak Rapat Fakultas',
            'jenis' => Item::JENIS_MAKANAN,
            'kategori' => 'makanan_berat',
            'jumlah' => 20,
            'area' => 'Kampus Utara',
            'lokasi_detail' => 'Lobi Gedung B',
        ]);

        $this->buatItemSelesai($dewi, $budi, now()->subWeeks(1)->setTime(15, 0), [
            'judul' => 'Kemeja Kerja Bekas',
            'jenis' => Item::JENIS_PAKAIAN,
            'kategori' => 'pakaian_dewasa',
            'kondisi' => 'layak_pakai',
            'jumlah' => 8,
            'area' => 'Perumahan Griya Asri',
            'lokasi_detail' => 'Rumah nomor 12',
        ]);

        $this->buatItemSelesai($siti, $rizky, now()->subWeeks(2)->setTime(11, 0), [
            'judul' => 'Roti Sisa Bazar',
            'jenis' => Item::JENIS_MAKANAN,
            'kategori' => 'kue_roti',
            'jumlah' => 15,
            'area' => 'Kampus Utara',
            'lokasi_detail' => 'Sekretariat BEM',
        ]);

        $this->buatItemSelesai($rizky, $budi, now()->subWeeks(3)->setTime(9, 0), [
            'judul' => 'Selimut Bantuan Banjir',
            'jenis' => Item::JENIS_PAKAIAN,
            'kategori' => 'selimut_kain',
            'kondisi' => 'layak_pakai',
            'jumlah' => 5,
            'area' => 'Pasar Pagi',
            'lokasi_detail' => 'Kios nomor 7',
        ]);

        $this->buatItemSelesai($dewi, $rizky, now()->subWeeks(4)->setTime(13, 0), [
            'judul' => 'Buku Pelajaran Bekas',
            'jenis' => Item::JENIS_BARANG,
            'kategori' => 'buku_alat_tulis',
            'kondisi' => 'layak_pakai',
            'jumlah' => 12,
            'area' => 'Perumahan Griya Asri',
            'lokasi_detail' => 'Rumah nomor 12',
        ]);

        $this->buatItemSelesai($siti, $budi, now()->subWeeks(5)->setTime(12, 0), [
            'judul' => 'Nasi Kotak Wisuda',
            'jenis' => Item::JENIS_MAKANAN,
            'kategori' => 'makanan_berat',
            'jumlah' => 30,
            'area' => 'Kampus Utara',
            'lokasi_detail' => 'Aula utama',
        ]);

        Notifikasi::kirim(
            $dewi->id,
            Notifikasi::TIPE_KLAIM_SELESAI,
            'Serah terima selesai',
            'Klaim atas "Nasi Kotak Rapat Fakultas" dinyatakan selesai.'
        );
    }

    /**
     * Satu laporan pending supaya antrean panel admin tidak kosong.
     */
    private function laporanContoh(User $dewi, User $rizky): void
    {
        $item = Item::where('judul', 'Kipas Angin Meja')->first();

        if ($item) {
            Report::create([
                'item_id' => $item->id,
                'user_id' => $rizky->id,
                'alasan' => 'tidak_sesuai_foto',
                'keterangan' => 'Kondisi barang berbeda dari deskripsi.',
                'status_tinjauan' => Report::STATUS_PENDING,
            ]);
        }
    }

    private function buatItem(User $pemberi, array $data): Item
    {
        return Item::create($data + [
            'user_id' => $pemberi->id,
            'status' => Item::STATUS_AVAILABLE,
            'jumlah' => 1,
        ]);
    }

    /**
     * Item yang sudah selesai diserahkan pada waktu tertentu, beserta klaimnya.
     * Kolom waktu diisi manual, jadi pembuatan memakai unguarded.
     */
    private function buatItemSelesai(User $pemberi, User $penerima, Carbon $waktu, array $data): void
    {
        if (($data['jenis'] ?? '') === Item::JENIS_MAKANAN) {
            $data += [
                'waktu_dibuat_makanan' => $waktu->copy()->subHour(),
                'expires_at' => $waktu->copy()->addHours(3),
            ];
        }

        $item = Item::unguarded(fn () => Item::create($data + [
            'user_id' => $pemberi->id,
            'status' => Item::STATUS_COMPLETED,
            'created_at' => $waktu->copy()->subHours(2),
            'updated_at' => $waktu->copy()->addMinutes(20),
        ]));

        Claim::unguarded(fn () => Claim::create([
            'item_id' => $item->id,
            'user_id' => $penerima->id,
            'kode_klaim' => Claim::buatKode(),
            'locked_until' => $waktu->copy()->addMinutes(30),
            'status' => Claim::STATUS_COMPLETED,
            'dikonfirmasi_pemberi_at' => $waktu->copy()->addMinutes(20),
            'dikonfirmasi_penerima_at' => $waktu->copy()->addMinutes(22),
            'completed_at' => $waktu->copy()->addMinutes(20),
            'created_at' => $waktu,
            'updated_at' => $waktu->copy()->addMinutes(20),
        ]));
    }

    private function ringkasan(): void
    {
        $this->command?->info('Seeder selesai. Akun dummy (kata sandi semuanya: ' . self::PASSWORD_DUMMY . '):');
        $this->command?->line('  admin@bagirasa.test (admin), siti@, budi@, dewi@, rizky@bagirasa.test');
    }
}