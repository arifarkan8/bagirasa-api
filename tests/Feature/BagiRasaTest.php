<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\Item;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BagiRasaTest extends TestCase
{
    use RefreshDatabase;

    private int $urut = 0;

    /**
     * Penjaga: RefreshDatabase menghapus seluruh tabel. Tes hanya boleh jalan
     * di database khusus uji, bukan di database pengembangan (bagirasa).
     */
    protected function beforeRefreshingDatabase(): void
    {
        $koneksi = config('database.default');
        $nama = (string) config("database.connections.{$koneksi}.database");

        if ($nama !== ':memory:' && ! str_ends_with($nama, '_test')) {
            throw new \RuntimeException(
                "Tes dihentikan demi keamanan data. Database aktif: \"{$nama}\". "
                . 'Gunakan database khusus uji (nama berakhiran _test) lewat file .env.testing.'
            );
        }
    }

    // ------------------------------------------------------------------
    // Pembantu
    // ------------------------------------------------------------------

    private function buatUser(array $o = []): User
    {
        $this->urut++;

        return User::create(array_merge([
            'name' => "Pengguna {$this->urut}",
            'email' => "pengguna{$this->urut}@tes.test",
            'password' => 'password123',
            'no_whatsapp' => '08120000' . str_pad((string) $this->urut, 4, '0', STR_PAD_LEFT),
            'area' => 'Kampus',
        ], $o))->refresh();
    }

    private function buatItem(User $pemberi, array $o = []): Item
    {
        return Item::create(array_merge([
            'user_id' => $pemberi->id,
            'judul' => 'Nasi Kotak Seminar',
            'jenis' => Item::JENIS_MAKANAN,
            'kategori' => 'makanan_berat',
            'jumlah' => 5,
            'area' => 'Kampus',
            'lokasi_detail' => 'Lobi Gedung A',
            'waktu_dibuat_makanan' => now()->subMinutes(30),
            'expires_at' => now()->addHours(3),
            'status' => Item::STATUS_AVAILABLE,
        ], $o));
    }

    private function buatKlaim(Item $item, User $penerima, array $o = []): Claim
    {
        return Claim::create(array_merge([
            'item_id' => $item->id,
            'user_id' => $penerima->id,
            'kode_klaim' => Claim::buatKode(),
            'locked_until' => now()->addMinutes(30),
            'status' => Claim::STATUS_ACTIVE,
        ], $o));
    }

    private function dataMakanan(array $o = []): array
    {
        return array_merge([
            'jenis' => 'makanan',
            'judul' => 'Nasi Kotak Seminar',
            'deskripsi' => 'Baru dimasak pagi ini.',
            'kategori' => 'makanan_berat',
            'jumlah' => 10,
            'area' => 'Kampus',
            'lokasi_detail' => 'Lobi Gedung A',
            'waktu_dibuat_makanan' => now()->subMinutes(10)->toIso8601String(),
            'pernyataan_layak' => true,
        ], $o);
    }

    // ------------------------------------------------------------------
    // Registrasi dan peran
    // ------------------------------------------------------------------

    public function test_registrasi_tidak_bisa_mendaftar_sebagai_admin(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Calon Admin',
            'email' => 'calon@tes.test',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'no_whatsapp' => '081234567890',
            'area' => 'Kampus',
            'setuju_kebijakan' => true,
            'role' => 'admin',
            'status_akun' => 'aktif',
        ])->assertCreated()
            ->assertJsonPath('user.role', 'user');

        $this->assertSame('user', User::where('email', 'calon@tes.test')->value('role'));
    }

    public function test_pengguna_biasa_ditolak_di_panel_admin(): void
    {
        $this->getJson('/api/admin/akun')->assertUnauthorized();

        Sanctum::actingAs($this->buatUser());
        $this->getJson('/api/admin/akun')->assertForbidden();

        Sanctum::actingAs($this->buatUser(['role' => 'admin']));
        $this->getJson('/api/admin/akun')->assertOk();
    }

    // ------------------------------------------------------------------
    // Auto-expiry
    // ------------------------------------------------------------------

    public function test_item_kedaluwarsa_tidak_bisa_diklaim(): void
    {
        $pemberi = $this->buatUser();
        $penerima = $this->buatUser();
        $item = $this->buatItem($pemberi, ['expires_at' => now()->subMinute()]);

        Sanctum::actingAs($penerima);

        $this->postJson("/api/items/{$item->id}/claim")
            ->assertStatus(422)
            ->assertJsonValidationErrors('item');

        $this->assertSame(0, Claim::count());
    }

    public function test_item_kedaluwarsa_tidak_tampil_di_daftar_publik(): void
    {
        $pemberi = $this->buatUser();
        $segar = $this->buatItem($pemberi, ['judul' => 'Masih Segar']);
        $this->buatItem($pemberi, [
            'judul' => 'Sudah Lewat',
            'expires_at' => now()->subHour(),
        ]);

        $this->getJson('/api/items')
            ->assertOk()
            ->assertJsonCount(1, 'items.data')
            ->assertJsonPath('items.data.0.id', $segar->id);
    }

    public function test_pakaian_tanpa_batas_waktu_tetap_tampil(): void
    {
        $pemberi = $this->buatUser();
        $this->buatItem($pemberi, [
            'judul' => 'Jaket Layak Pakai',
            'jenis' => Item::JENIS_PAKAIAN,
            'kategori' => 'pakaian_dewasa',
            'kondisi' => 'layak_pakai',
            'waktu_dibuat_makanan' => null,
            'expires_at' => null,
        ]);

        $this->getJson('/api/items')->assertOk()->assertJsonCount(1, 'items.data');
    }

    // ------------------------------------------------------------------
    // Klaim dan lock
    // ------------------------------------------------------------------

    public function test_klaim_berhasil_mengunci_item_dan_memberi_tautan_whatsapp(): void
    {
        $pemberi = $this->buatUser(['no_whatsapp' => '081234500001']);
        $penerima = $this->buatUser();
        $item = $this->buatItem($pemberi);

        Sanctum::actingAs($penerima);

        $res = $this->postJson("/api/items/{$item->id}/claim")
            ->assertCreated()
            ->assertJsonPath('claim.status', 'active');

        $this->assertStringStartsWith('BR-', $res->json('claim.kode_klaim'));
        $this->assertStringStartsWith('https://wa.me/6281234500001', $res->json('claim.tautan_whatsapp'));
        $this->assertSame(Item::STATUS_LOCKED, $item->fresh()->status);
    }

    public function test_item_yang_sudah_terkunci_tidak_bisa_diklaim_orang_kedua(): void
    {
        $pemberi = $this->buatUser();
        $pertama = $this->buatUser();
        $kedua = $this->buatUser();
        $item = $this->buatItem($pemberi);

        Sanctum::actingAs($pertama);
        $this->postJson("/api/items/{$item->id}/claim")->assertCreated();

        Sanctum::actingAs($kedua);
        $this->postJson("/api/items/{$item->id}/claim")
            ->assertStatus(422)
            ->assertJsonValidationErrors('item');

        $this->assertSame(1, Claim::where('item_id', $item->id)->count());
    }

    public function test_pemberi_tidak_bisa_mengklaim_item_sendiri(): void
    {
        $pemberi = $this->buatUser();
        $item = $this->buatItem($pemberi);

        Sanctum::actingAs($pemberi);

        $this->postJson("/api/items/{$item->id}/claim")
            ->assertStatus(422)
            ->assertJsonValidationErrors('item');
    }

    public function test_maksimal_dua_klaim_aktif_per_pengguna(): void
    {
        $pemberi = $this->buatUser();
        $penerima = $this->buatUser();
        $a = $this->buatItem($pemberi, ['judul' => 'Item A']);
        $b = $this->buatItem($pemberi, ['judul' => 'Item B']);
        $c = $this->buatItem($pemberi, ['judul' => 'Item C']);

        Sanctum::actingAs($penerima);

        $this->postJson("/api/items/{$a->id}/claim")->assertCreated();
        $this->postJson("/api/items/{$b->id}/claim")->assertCreated();
        $this->postJson("/api/items/{$c->id}/claim")
            ->assertStatus(422)
            ->assertJsonValidationErrors('item');

        $this->assertSame(Item::STATUS_AVAILABLE, $c->fresh()->status);
    }

    public function test_lock_yang_habis_dilepas_dan_dicatat_sebagai_no_show(): void
    {
        $pemberi = $this->buatUser();
        $lama = $this->buatUser();
        $baru = $this->buatUser();
        $item = $this->buatItem($pemberi, ['status' => Item::STATUS_LOCKED]);
        $klaimLama = $this->buatKlaim($item, $lama, ['locked_until' => now()->subMinutes(5)]);

        Sanctum::actingAs($baru);

        $this->postJson("/api/items/{$item->id}/claim")->assertCreated();

        $this->assertSame(Claim::STATUS_EXPIRED, $klaimLama->fresh()->status);
        $this->assertSame(1, $lama->fresh()->no_show_count);
        $this->assertSame(Item::STATUS_LOCKED, $item->fresh()->status);
        $this->assertSame(1, Claim::where('item_id', $item->id)->where('status', Claim::STATUS_ACTIVE)->count());
    }

    public function test_akun_yang_dibatasi_tidak_bisa_mengklaim(): void
    {
        $pemberi = $this->buatUser();
        $penerima = $this->buatUser([
            'status_akun' => 'dibatasi',
            'dibatasi_sampai' => now()->addHour(),
        ]);
        $item = $this->buatItem($pemberi);

        Sanctum::actingAs($penerima);

        $this->postJson("/api/items/{$item->id}/claim")->assertForbidden();
        $this->assertSame(0, Claim::count());
    }

    // ------------------------------------------------------------------
    // Pembatalan dan konfirmasi selesai
    // ------------------------------------------------------------------

    public function test_pembatalan_klaim_mengembalikan_item_menjadi_tersedia(): void
    {
        $pemberi = $this->buatUser();
        $penerima = $this->buatUser();
        $item = $this->buatItem($pemberi, ['status' => Item::STATUS_LOCKED]);
        $klaim = $this->buatKlaim($item, $penerima);

        Sanctum::actingAs($penerima);

        $this->postJson("/api/claims/{$klaim->id}/cancel")->assertOk();

        $this->assertSame(Claim::STATUS_CANCELLED, $klaim->fresh()->status);
        $this->assertSame(Item::STATUS_AVAILABLE, $item->fresh()->status);
    }

    public function test_orang_lain_tidak_bisa_membatalkan_klaim_milik_penerima(): void
    {
        $pemberi = $this->buatUser();
        $penerima = $this->buatUser();
        $orangLain = $this->buatUser();
        $item = $this->buatItem($pemberi, ['status' => Item::STATUS_LOCKED]);
        $klaim = $this->buatKlaim($item, $penerima);

        Sanctum::actingAs($orangLain);

        $this->postJson("/api/claims/{$klaim->id}/cancel")->assertForbidden();
        $this->assertSame(Claim::STATUS_ACTIVE, $klaim->fresh()->status);
    }

    public function test_pemberi_menyelesaikan_klaim_dan_no_show_penerima_direset(): void
    {
        $pemberi = $this->buatUser();
        $penerima = $this->buatUser(['no_show_count' => 2]);
        $item = $this->buatItem($pemberi, ['status' => Item::STATUS_LOCKED]);
        $klaim = $this->buatKlaim($item, $penerima);

        Sanctum::actingAs($pemberi);

        $this->postJson("/api/claims/{$klaim->id}/complete")->assertOk();

        $this->assertSame(Claim::STATUS_COMPLETED, $klaim->fresh()->status);
        $this->assertSame(Item::STATUS_COMPLETED, $item->fresh()->status);
        $this->assertSame(0, $penerima->fresh()->no_show_count);
    }

    public function test_konfirmasi_penerima_saja_belum_menyelesaikan_klaim(): void
    {
        $pemberi = $this->buatUser();
        $penerima = $this->buatUser();
        $item = $this->buatItem($pemberi, ['status' => Item::STATUS_LOCKED]);
        $klaim = $this->buatKlaim($item, $penerima);

        Sanctum::actingAs($penerima);

        $this->postJson("/api/claims/{$klaim->id}/complete")->assertOk();

        $klaim->refresh();
        $this->assertSame(Claim::STATUS_ACTIVE, $klaim->status);
        $this->assertNotNull($klaim->dikonfirmasi_penerima_at);
    }

    // ------------------------------------------------------------------
    // Otorisasi dan privasi
    // ------------------------------------------------------------------

    public function test_pengguna_tidak_bisa_mengubah_atau_menghapus_item_orang_lain(): void
    {
        $pemilik = $this->buatUser();
        $orangLain = $this->buatUser();
        $item = $this->buatItem($pemilik);

        Sanctum::actingAs($orangLain);

        $this->postJson("/api/items/{$item->id}", ['judul' => 'Dibajak'])->assertForbidden();
        $this->deleteJson("/api/items/{$item->id}")->assertForbidden();

        $this->assertSame('Nasi Kotak Seminar', $item->fresh()->judul);
    }

    public function test_item_selesai_tidak_bisa_dihapus_tetapi_item_tersedia_bisa(): void
    {
        $pemilik = $this->buatUser();
        $selesai = $this->buatItem($pemilik, ['status' => Item::STATUS_COMPLETED]);
        $tersedia = $this->buatItem($pemilik, ['judul' => 'Masih Tersedia']);

        Sanctum::actingAs($pemilik);

        $this->deleteJson("/api/items/{$selesai->id}")->assertStatus(422);
        $this->assertSame(Item::STATUS_COMPLETED, $selesai->fresh()->status);

        $this->deleteJson("/api/items/{$tersedia->id}")->assertOk();
        $this->assertSoftDeleted('items', ['id' => $tersedia->id]);
    }

    public function test_daftar_publik_tidak_membocorkan_lokasi_dan_whatsapp(): void
    {
        $this->buatItem($this->buatUser());

        $this->getJson('/api/items')
            ->assertOk()
            ->assertJsonMissingPath('items.data.0.lokasi_detail')
            ->assertJsonMissingPath('items.data.0.pemberi.no_whatsapp');
    }

    public function test_lokasi_detail_hanya_terbuka_untuk_penerima_dengan_klaim_aktif(): void
    {
        $pemberi = $this->buatUser();
        $penerima = $this->buatUser();
        $orangLain = $this->buatUser();
        $item = $this->buatItem($pemberi, ['status' => Item::STATUS_LOCKED]);
        $this->buatKlaim($item, $penerima);

        // Tanpa login
        $this->getJson("/api/items/{$item->id}")
            ->assertOk()
            ->assertJsonMissingPath('item.lokasi_detail');

        // Orang lain tanpa klaim
        Sanctum::actingAs($orangLain);
        $this->getJson("/api/items/{$item->id}")
            ->assertOk()
            ->assertJsonMissingPath('item.lokasi_detail');

        // Penerima dengan klaim aktif
        Sanctum::actingAs($penerima);
        $this->getJson("/api/items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('item.lokasi_detail', 'Lobi Gedung A');
    }

    // ------------------------------------------------------------------
    // Food Safety Guard
    // ------------------------------------------------------------------

    public function test_makanan_dengan_kata_berisiko_ditolak(): void
    {
        Sanctum::actingAs($this->buatUser());

        $this->postJson('/api/items', $this->dataMakanan(['judul' => 'Nasi basi kemarin']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('judul');
    }

    public function test_durasi_melebihi_batas_kategori_ditolak(): void
    {
        Sanctum::actingAs($this->buatUser());

        $this->postJson('/api/items', $this->dataMakanan(['durasi_jam' => 6]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('durasi_jam');
    }

    public function test_makanan_tanpa_pernyataan_layak_ditolak(): void
    {
        Sanctum::actingAs($this->buatUser());

        $data = $this->dataMakanan();
        unset($data['pernyataan_layak']);

        $this->postJson('/api/items', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors('pernyataan_layak');
    }

    public function test_makanan_valid_diposting_dengan_masa_berlaku_default_tiga_jam(): void
    {
        Sanctum::actingAs($this->buatUser());

        $this->postJson('/api/items', $this->dataMakanan())->assertCreated();

        $item = Item::latest('id')->first();

        $this->assertSame(Item::STATUS_AVAILABLE, $item->status);
        $this->assertEqualsWithDelta(
            now()->addHours(3)->timestamp,
            $item->expires_at->timestamp,
            120
        );
    }

    // ------------------------------------------------------------------
    // Laporan
    // ------------------------------------------------------------------

    public function test_laporan_ganda_dan_laporan_ke_item_sendiri_ditolak(): void
    {
        $pemilik = $this->buatUser();
        $pelapor = $this->buatUser();
        $item = $this->buatItem($pemilik);

        Sanctum::actingAs($pemilik);
        $this->postJson("/api/items/{$item->id}/report", ['alasan' => 'basi'])->assertStatus(422);

        Sanctum::actingAs($pelapor);
        $this->postJson("/api/items/{$item->id}/report", ['alasan' => 'basi'])->assertCreated();
        $this->postJson("/api/items/{$item->id}/report", ['alasan' => 'basi'])->assertStatus(422);

        $this->assertSame(1, Report::where('item_id', $item->id)->count());
    }
}