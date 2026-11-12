<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Claim;
use App\Models\Item;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ItemController extends Controller
{
    /**
     * Kategori yang diizinkan untuk setiap jenis.
     */
    private const KATEGORI = [
        Item::JENIS_MAKANAN => ['makanan_berat', 'kue_roti', 'minuman', 'makanan_kemasan'],
        Item::JENIS_PAKAIAN => ['pakaian_dewasa', 'pakaian_anak', 'selimut_kain', 'sepatu_tas'],
        Item::JENIS_BARANG => ['perabot', 'elektronik', 'buku_alat_tulis', 'perlengkapan_bayi', 'kebutuhan_bencana', 'lainnya'],
    ];

    /**
     * Batas maksimum masa kelayakan makanan (jam) per kategori (Food Safety Guard).
     */
    private const MAKS_JAM = [
        'makanan_berat' => 4,
        'kue_roti' => 12,
        'minuman' => 6,
        'makanan_kemasan' => 72,
    ];

    private const DEFAULT_JAM = 3;

    private const KONDISI = ['baru', 'layak_pakai', 'perlu_perbaikan'];

    /**
     * Kata yang menandakan makanan berisiko dan tidak boleh diposting.
     */
    private const KATA_TERLARANG = ['mentah', 'basi', 'sisa makan', 'sisa piring'];

    private const MAKS_POSTING_PER_HARI = 10;

    /**
     * Daftar item dengan pencarian dan filter. Rute ini publik.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'jenis' => ['nullable', Rule::in(array_keys(self::KATEGORI))],
            'kategori' => ['nullable', 'string', 'max:50'],
            'area' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Item::tampil()->with('pemberi:id,name');

        if ($request->filled('q')) {
            $kata = $request->input('q');
            $query->where(function ($q) use ($kata) {
                $q->where('judul', 'like', "%{$kata}%")
                    ->orWhere('deskripsi', 'like', "%{$kata}%");
            });
        }

        if ($request->filled('jenis')) {
            $query->where('jenis', $request->input('jenis'));
        }

        if ($request->filled('kategori')) {
            $query->where('kategori', $request->input('kategori'));
        }

        if ($request->filled('area')) {
            $query->where('area', $request->input('area'));
        }

        $hasil = $query->urutMendesak()
            ->paginate($request->integer('per_page', 12))
            ->through(fn (Item $item) => $this->format($item));

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'items' => $hasil,
        ]);
    }

    /**
     * Detail item. Lokasi detail dan nomor WhatsApp hanya untuk pemilik
     * dan penerima dengan klaim aktif.
     */
    public function show(Request $request, Item $item): JsonResponse
    {
        $user = $request->user('sanctum');

        if ($item->status === Item::STATUS_HIDDEN && (! $user || ! $this->boleh($user, $item))) {
            return response()->json(['message' => 'Item tidak ditemukan.'], 404);
        }

        $item->load('pemberi:id,name,no_whatsapp');

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'item' => $this->format($item, $this->punyaAkses($user, $item)),
        ]);
    }

    /**
     * Daftar postingan milik pengguna yang sedang login.
     */
    public function milikSaya(Request $request): JsonResponse
    {
        $hasil = Item::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(12)
            ->through(fn (Item $item) => $this->format($item, true));

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'items' => $hasil,
        ]);
    }

    /**
     * Membuat item baru, termasuk Food Safety Guard untuk makanan.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $jumlahHariIni = Item::withTrashed()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($jumlahHariIni >= self::MAKS_POSTING_PER_HARI) {
            return response()->json([
                'message' => 'Batas posting harian tercapai. Coba lagi besok.',
            ], 429);
        }

        $request->validate([
            'jenis' => ['required', Rule::in(array_keys(self::KATEGORI))],
        ]);

        $jenis = $request->input('jenis');

        $aturan = [
            'judul' => ['required', 'string', 'max:150'],
            'deskripsi' => ['nullable', 'string', 'max:2000'],
            'kategori' => ['required', Rule::in(self::KATEGORI[$jenis])],
            'jumlah' => ['required', 'integer', 'min:1', 'max:1000'],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'area' => ['required', 'string', 'max:100'],
            'lokasi_detail' => ['required', 'string', 'max:500'],
        ];

        if ($jenis === Item::JENIS_MAKANAN) {
            $aturan['waktu_dibuat_makanan'] = ['required', 'date', 'before_or_equal:now'];
            $aturan['durasi_jam'] = ['nullable', 'integer', 'min:1'];
            $aturan['pernyataan_layak'] = ['accepted'];
        } else {
            $aturan['kondisi'] = ['required', Rule::in(self::KONDISI)];
            $aturan['tersedia_sampai'] = ['nullable', 'date', 'after:now'];
        }

        $data = $request->validate($aturan, [
            'pernyataan_layak.accepted' => 'Anda harus menyatakan makanan layak konsumsi dan diolah higienis.',
        ]);

        $expiresAt = null;
        $waktuDibuat = null;

        if ($jenis === Item::JENIS_MAKANAN) {
            $this->cekKataTerlarang($data['judul'], $data['deskripsi'] ?? '');

            $waktuDibuat = \Illuminate\Support\Carbon::parse($data['waktu_dibuat_makanan']);
            $maksJam = self::MAKS_JAM[$data['kategori']];
            $durasi = (int) ($data['durasi_jam'] ?? self::DEFAULT_JAM);

            if ($durasi > $maksJam) {
                throw ValidationException::withMessages([
                    'durasi_jam' => "Durasi maksimum untuk kategori ini adalah {$maksJam} jam.",
                ]);
            }

            $expiresAt = now()->addHours($durasi);
            $batasProduksi = $waktuDibuat->copy()->addHours($maksJam);

            if ($batasProduksi->lessThan($expiresAt)) {
                $expiresAt = $batasProduksi;
            }

            if ($expiresAt->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages([
                    'waktu_dibuat_makanan' => 'Makanan ini sudah melewati batas kelayakan, tidak bisa diposting.',
                ]);
            }
        } elseif (! empty($data['tersedia_sampai'])) {
            $expiresAt = \Illuminate\Support\Carbon::parse($data['tersedia_sampai']);
        }

        $path = $request->hasFile('foto')
            ? $request->file('foto')->store('items', 'public')
            : null;

        $item = Item::create([
            'user_id' => $user->id,
            'judul' => $data['judul'],
            'deskripsi' => $data['deskripsi'] ?? null,
            'jenis' => $jenis,
            'kategori' => $data['kategori'],
            'kondisi' => $data['kondisi'] ?? null,
            'jumlah' => $data['jumlah'],
            'foto' => $path,
            'area' => $data['area'],
            'lokasi_detail' => $data['lokasi_detail'],
            'waktu_dibuat_makanan' => $waktuDibuat,
            'expires_at' => $expiresAt,
            'status' => Item::STATUS_AVAILABLE,
        ]);

        ActivityLog::catat('item_dibuat', $user->id, 'item', $item->id, ['jenis' => $jenis], $request->ip());

        return response()->json([
            'message' => 'Item berhasil diposting.',
            'server_time' => now()->toIso8601String(),
            'item' => $this->format($item->load('pemberi:id,name,no_whatsapp'), true),
        ], 201);
    }

    /**
     * Mengubah item milik sendiri. Hanya saat status masih available.
     * Jenis dan masa berlaku tidak bisa diubah.
     */
    public function update(Request $request, Item $item): JsonResponse
    {
        $user = $request->user();

        if ($item->user_id !== $user->id) {
            return response()->json(['message' => 'Anda tidak berhak mengubah item ini.'], 403);
        }

        if ($item->statusEfektif() !== Item::STATUS_AVAILABLE) {
            return response()->json([
                'message' => 'Item hanya bisa diubah saat berstatus tersedia.',
            ], 422);
        }

        $aturan = [
            'judul' => ['sometimes', 'required', 'string', 'max:150'],
            'deskripsi' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'kategori' => ['sometimes', 'required', Rule::in(self::KATEGORI[$item->jenis])],
            'jumlah' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000'],
            'foto' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'area' => ['sometimes', 'required', 'string', 'max:100'],
            'lokasi_detail' => ['sometimes', 'required', 'string', 'max:500'],
        ];

        if ($item->jenis !== Item::JENIS_MAKANAN) {
            $aturan['kondisi'] = ['sometimes', 'required', Rule::in(self::KONDISI)];
        }

        $data = $request->validate($aturan);

        if ($item->jenis === Item::JENIS_MAKANAN) {
            $this->cekKataTerlarang(
                $data['judul'] ?? $item->judul,
                $data['deskripsi'] ?? ($item->deskripsi ?? '')
            );
        }

        if ($request->hasFile('foto')) {
            if ($item->foto) {
                Storage::disk('public')->delete($item->foto);
            }
            $data['foto'] = $request->file('foto')->store('items', 'public');
        }

        $item->update($data);

        ActivityLog::catat('item_diubah', $user->id, 'item', $item->id, null, $request->ip());

        return response()->json([
            'message' => 'Item diperbarui.',
            'server_time' => now()->toIso8601String(),
            'item' => $this->format($item->fresh()->load('pemberi:id,name,no_whatsapp'), true),
        ]);
    }

    /**
     * Menghapus item milik sendiri (soft delete). Tidak bisa saat sedang
     * terkunci, dan tidak bisa untuk item yang sudah selesai diserahkan
     * supaya riwayat serah terima tetap akurat.
     */
    public function destroy(Request $request, Item $item): JsonResponse
    {
        $user = $request->user();

        if ($item->user_id !== $user->id) {
            return response()->json(['message' => 'Anda tidak berhak menghapus item ini.'], 403);
        }

        if ($item->status === Item::STATUS_COMPLETED) {
            return response()->json([
                'message' => 'Item yang sudah selesai diserahkan tidak bisa dihapus.',
            ], 422);
        }

        if ($item->status === Item::STATUS_LOCKED && $item->klaimAktif()->exists()) {
            return response()->json([
                'message' => 'Item sedang diklaim. Tunggu klaim selesai atau dibatalkan.',
            ], 422);
        }

        $item->update(['status' => Item::STATUS_CANCELLED]);
        $item->delete();

        ActivityLog::catat('item_dihapus', $user->id, 'item', $item->id, null, $request->ip());

        return response()->json(['message' => 'Item dihapus.']);
    }

    /**
     * Menolak postingan makanan yang memuat kata berisiko.
     */
    private function cekKataTerlarang(string $judul, string $deskripsi): void
    {
        $teks = mb_strtolower($judul . ' ' . $deskripsi);

        foreach (self::KATA_TERLARANG as $kata) {
            if (str_contains($teks, $kata)) {
                throw ValidationException::withMessages([
                    'judul' => "Postingan memuat kata \"{$kata}\". Makanan berisiko tidak boleh dibagikan.",
                ]);
            }
        }
    }

    private function boleh(User $user, Item $item): bool
    {
        return $user->id === $item->user_id || $user->isAdmin();
    }

    /**
     * Akses ke data sensitif: pemilik, admin, atau penerima dengan klaim aktif.
     */
    private function punyaAkses(?User $user, Item $item): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->boleh($user, $item)) {
            return true;
        }

        return Claim::aktifMilik($user->id)->where('item_id', $item->id)->exists();
    }

    /**
     * Bentuk respons item. Data sensitif hanya muncul bila $akses true.
     */
    private function format(Item $item, bool $akses = false): array
    {
        $data = [
            'id' => $item->id,
            'judul' => $item->judul,
            'deskripsi' => $item->deskripsi,
            'jenis' => $item->jenis,
            'kategori' => $item->kategori,
            'kondisi' => $item->kondisi,
            'jumlah' => $item->jumlah,
            'foto_url' => $item->foto ? asset('storage/' . $item->foto) : null,
            'area' => $item->area,
            'waktu_dibuat_makanan' => $item->waktu_dibuat_makanan?->toIso8601String(),
            'expires_at' => $item->expires_at?->toIso8601String(),
            'status' => $item->statusEfektif(),
            'bisa_diklaim' => $item->bisaDiklaim(),
            'pemberi' => [
                'id' => $item->pemberi?->id,
                'name' => $item->pemberi?->name,
            ],
            'created_at' => $item->created_at?->toIso8601String(),
        ];

        if ($akses) {
            $data['lokasi_detail'] = $item->lokasi_detail;
            $data['pemberi']['no_whatsapp'] = $item->pemberi?->no_whatsapp;
        }

        return $data;
    }
}