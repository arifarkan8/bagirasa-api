<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Claim;
use App\Models\Item;
use App\Models\Notifikasi;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClaimController extends Controller
{
    /**
     * Batas klaim hangus berturut-turut sebelum akun dibatasi,
     * dan lama pembatasannya (jam).
     */
    private const BATAS_NO_SHOW = 3;
    private const DURASI_PEMBATASAN_JAM = 24;

    /**
     * Klaim sebuah item: transaction + lockForUpdate agar dua permintaan
     * bersamaan tidak bisa sama-sama lolos.
     */
    public function store(Request $request, int $itemId): JsonResponse
    {
        $user = $request->user();

        $this->cekAkun($user);

        $claim = DB::transaction(function () use ($user, $itemId, $request) {
            // Kunci baris item. Request lain ke item yang sama menunggu di sini.
            $item = Item::whereKey($itemId)->lockForUpdate()->first();

            if (! $item || $item->status === Item::STATUS_HIDDEN) {
                abort(404, 'Item tidak ditemukan.');
            }

            if ($item->user_id === $user->id) {
                throw ValidationException::withMessages([
                    'item' => 'Anda tidak bisa mengklaim item milik sendiri.',
                ]);
            }

            // Rapikan klaim yang lock-nya sudah habis pada item ini.
            $this->rapikanKlaimHabis($item);

            if ($item->sudahKedaluwarsa()) {
                $item->update(['status' => Item::STATUS_EXPIRED]);
                throw ValidationException::withMessages([
                    'item' => 'Item ini sudah melewati batas kelayakan.',
                ]);
            }

            if ($item->status !== Item::STATUS_AVAILABLE) {
                throw ValidationException::withMessages([
                    'item' => 'Item ini sedang diklaim orang lain atau sudah tidak tersedia.',
                ]);
            }

            $aktif = Claim::aktifMilik($user->id)->count();

            if ($aktif >= Claim::MAKS_KLAIM_AKTIF) {
                throw ValidationException::withMessages([
                    'item' => 'Anda sudah punya ' . Claim::MAKS_KLAIM_AKTIF . ' klaim aktif. Selesaikan atau batalkan dulu.',
                ]);
            }

            $claim = Claim::create([
                'item_id' => $item->id,
                'user_id' => $user->id,
                'kode_klaim' => Claim::buatKode(),
                'locked_until' => $this->batasLock($item),
                'status' => Claim::STATUS_ACTIVE,
            ]);

            $item->update(['status' => Item::STATUS_LOCKED]);

            Notifikasi::kirim(
                $item->user_id,
                Notifikasi::TIPE_ITEM_DIKLAIM,
                'Item Anda diklaim',
                "{$user->name} mengklaim \"{$item->judul}\" dengan kode {$claim->kode_klaim}.",
                $item->id,
                $claim->id
            );

            ActivityLog::catat('klaim_dibuat', $user->id, 'claim', $claim->id, ['item_id' => $item->id], $request->ip());

            return $claim;
        });

        $claim->load('item.pemberi:id,name,no_whatsapp');

        return response()->json([
            'message' => 'Klaim berhasil. Item dikunci untuk Anda.',
            'server_time' => now()->toIso8601String(),
            'claim' => $this->format($claim, true),
        ], 201);
    }

    /**
     * Riwayat klaim milik pengguna yang login.
     */
    public function saya(Request $request): JsonResponse
    {
        $hasil = Claim::where('user_id', $request->user()->id)
            ->with('item.pemberi:id,name,no_whatsapp')
            ->latest()
            ->paginate(12)
            ->through(fn (Claim $c) => $this->format($c, $c->status === Claim::STATUS_ACTIVE && ! $c->lockHabis()));

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'claims' => $hasil,
        ]);
    }

    /**
     * Daftar klaim yang masuk ke item milik pengguna (sisi Pemberi).
     */
    public function masuk(Request $request): JsonResponse
    {
        $hasil = Claim::whereHas('item', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with(['item', 'penerima:id,name'])
            ->latest()
            ->paginate(12)
            ->through(fn (Claim $c) => $this->format($c, false) + [
                'penerima' => ['id' => $c->penerima?->id, 'name' => $c->penerima?->name],
            ]);

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'claims' => $hasil,
        ]);
    }

    /**
     * Detail satu klaim, untuk Penerima atau Pemberi item.
     */
    public function show(Request $request, Claim $claim): JsonResponse
    {
        $user = $request->user();
        $claim->load('item.pemberi:id,name,no_whatsapp');

        $penerima = $claim->user_id === $user->id;
        $pemberi = $claim->item->user_id === $user->id;

        if (! $penerima && ! $pemberi && ! $user->isAdmin()) {
            return response()->json(['message' => 'Anda tidak berhak melihat klaim ini.'], 403);
        }

        $akses = $penerima && $claim->status === Claim::STATUS_ACTIVE && ! $claim->lockHabis();

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'claim' => $this->format($claim, $akses),
        ]);
    }

    /**
     * Pembatalan klaim oleh Penerima. Item kembali tersedia bila belum kedaluwarsa.
     */
    public function cancel(Request $request, int $claimId): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user, $claimId, $request) {
            $claim = Claim::whereKey($claimId)->lockForUpdate()->first();

            if (! $claim || $claim->user_id !== $user->id) {
                abort(403, 'Anda tidak berhak membatalkan klaim ini.');
            }

            if ($claim->status !== Claim::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'claim' => 'Klaim ini sudah tidak aktif.',
                ]);
            }

            $item = Item::whereKey($claim->item_id)->lockForUpdate()->first();

            $claim->update([
                'status' => Claim::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

            $this->kembalikanItem($item);

            Notifikasi::kirim(
                $item->user_id,
                Notifikasi::TIPE_KLAIM_DIBATALKAN,
                'Klaim dibatalkan',
                "Klaim atas \"{$item->judul}\" dibatalkan. Item tersedia kembali.",
                $item->id,
                $claim->id
            );

            ActivityLog::catat('klaim_dibatalkan', $user->id, 'claim', $claim->id, null, $request->ip());
        });

        return response()->json(['message' => 'Klaim dibatalkan.']);
    }

    /**
     * Konfirmasi selesai. Pemberi mengonfirmasi untuk menyelesaikan klaim.
     * Konfirmasi Penerima dicatat sebagai tambahan.
     */
    public function complete(Request $request, int $claimId): JsonResponse
    {
        $user = $request->user();

        $pesan = DB::transaction(function () use ($user, $claimId, $request) {
            $claim = Claim::whereKey($claimId)->lockForUpdate()->first();

            if (! $claim) {
                abort(404, 'Klaim tidak ditemukan.');
            }

            $item = Item::whereKey($claim->item_id)->lockForUpdate()->first();

            $sebagaiPemberi = $item->user_id === $user->id;
            $sebagaiPenerima = $claim->user_id === $user->id;

            if (! $sebagaiPemberi && ! $sebagaiPenerima) {
                abort(403, 'Anda tidak berhak mengonfirmasi klaim ini.');
            }

            if ($claim->status !== Claim::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'claim' => 'Klaim ini sudah tidak aktif.',
                ]);
            }

            if ($sebagaiPemberi) {
                $claim->update([
                    'dikonfirmasi_pemberi_at' => now(),
                    'status' => Claim::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);

                $item->update(['status' => Item::STATUS_COMPLETED]);

                // Klaim selesai berarti riwayat no-show penerima dibersihkan.
                User::whereKey($claim->user_id)->update(['no_show_count' => 0]);

                Notifikasi::kirim(
                    $claim->user_id,
                    Notifikasi::TIPE_KLAIM_SELESAI,
                    'Serah terima selesai',
                    "Klaim atas \"{$item->judul}\" dinyatakan selesai.",
                    $item->id,
                    $claim->id
                );

                ActivityLog::catat('klaim_selesai', $user->id, 'claim', $claim->id, null, $request->ip());

                return 'Serah terima selesai.';
            }

            $claim->update(['dikonfirmasi_penerima_at' => now()]);

            ActivityLog::catat('klaim_dikonfirmasi_penerima', $user->id, 'claim', $claim->id, null, $request->ip());

            return 'Konfirmasi Anda tercatat. Menunggu konfirmasi Pemberi.';
        });

        return response()->json(['message' => $pesan]);
    }

    /**
     * Masa lock: durasi default, tapi tidak boleh melewati expires_at item.
     */
    private function batasLock(Item $item)
    {
        $lock = now()->addMinutes(Claim::DURASI_LOCK_MENIT);

        if ($item->expires_at && $item->expires_at->lessThan($lock)) {
            return $item->expires_at;
        }

        return $lock;
    }

    /**
     * Klaim active yang lock-nya habis pada satu item dijadikan expired,
     * dihitung sebagai no-show, lalu item dikembalikan.
     */
    private function rapikanKlaimHabis(Item $item): void
    {
        $habis = Claim::where('item_id', $item->id)
            ->lockHabis()
            ->get();

        foreach ($habis as $klaim) {
            $klaim->update(['status' => Claim::STATUS_EXPIRED]);
            $this->catatNoShow($klaim->user_id);

            Notifikasi::kirim(
                $klaim->user_id,
                Notifikasi::TIPE_KLAIM_KEDALUWARSA,
                'Klaim kedaluwarsa',
                "Waktu klaim atas \"{$item->judul}\" habis.",
                $item->id,
                $klaim->id
            );
        }

        if ($habis->isNotEmpty()) {
            $this->kembalikanItem($item);
        }
    }

    /**
     * Item kembali tersedia bila belum kedaluwarsa, selain itu expired.
     */
    private function kembalikanItem(Item $item): void
    {
        $item->refresh();

        if (! in_array($item->status, [Item::STATUS_LOCKED, Item::STATUS_AVAILABLE], true)) {
            return;
        }

        $item->update([
            'status' => $item->sudahKedaluwarsa() ? Item::STATUS_EXPIRED : Item::STATUS_AVAILABLE,
        ]);
    }

    /**
     * Menambah hitungan no-show. Tiga berturut-turut membuat akun dibatasi sementara.
     */
    private function catatNoShow(int $userId): void
    {
        $user = User::whereKey($userId)->lockForUpdate()->first();

        if (! $user) {
            return;
        }

        $jumlah = $user->no_show_count + 1;
        $ubah = ['no_show_count' => $jumlah];

        if ($jumlah >= self::BATAS_NO_SHOW) {
            $ubah['status_akun'] = 'dibatasi';
            $ubah['dibatasi_sampai'] = now()->addHours(self::DURASI_PEMBATASAN_JAM);
            $ubah['no_show_count'] = 0;
        }

        $user->update($ubah);

        ActivityLog::catat('no_show_dicatat', $userId, 'user', $userId, ['hitungan' => $jumlah]);
    }

    /**
     * Pengguna yang dibatasi tidak boleh mengklaim sampai batasnya lewat.
     */
    private function cekAkun(User $user): void
    {
        if ($user->status_akun === 'dibatasi' && $user->dibatasi_sampai && $user->dibatasi_sampai->isFuture()) {
            abort(403, 'Akun Anda dibatasi sementara sampai ' . $user->dibatasi_sampai->toIso8601String() . '.');
        }

        if ($user->status_akun === 'dibatasi' && (! $user->dibatasi_sampai || $user->dibatasi_sampai->isPast())) {
            $user->update(['status_akun' => 'aktif', 'dibatasi_sampai' => null]);
        }
    }

    /**
     * Tautan WhatsApp siap kirim, hanya dibuat untuk klaim aktif.
     */
    private function tautanWhatsapp(Claim $claim): ?string
    {
        $nomor = $claim->item?->pemberi?->no_whatsapp;

        if (! $nomor) {
            return null;
        }

        $nomor = preg_replace('/\D+/', '', $nomor);

        if (str_starts_with($nomor, '0')) {
            $nomor = '62' . substr($nomor, 1);
        }

        $pesan = "Halo, saya {$claim->penerima?->name} yang mengklaim \"{$claim->item->judul}\" "
            . "di BagiRasa. Kode klaim saya {$claim->kode_klaim}. Saya akan tiba sekitar [isi estimasi waktu].";

        return 'https://wa.me/' . $nomor . '?text=' . rawurlencode($pesan);
    }

    private function format(Claim $claim, bool $akses): array
    {
        $item = $claim->item;

        $data = [
            'id' => $claim->id,
            'kode_klaim' => $claim->kode_klaim,
            'status' => $claim->lockHabis() ? Claim::STATUS_EXPIRED : $claim->status,
            'locked_until' => $claim->locked_until?->toIso8601String(),
            'sisa_detik' => (int) $claim->sisaDetik(),
            'dikonfirmasi_pemberi_at' => $claim->dikonfirmasi_pemberi_at?->toIso8601String(),
            'dikonfirmasi_penerima_at' => $claim->dikonfirmasi_penerima_at?->toIso8601String(),
            'completed_at' => $claim->completed_at?->toIso8601String(),
            'created_at' => $claim->created_at?->toIso8601String(),
            'item' => $item ? [
                'id' => $item->id,
                'judul' => $item->judul,
                'jenis' => $item->jenis,
                'area' => $item->area,
                'status' => $item->statusEfektif(),
            ] : null,
        ];

        if ($akses && $item) {
            $data['item']['lokasi_detail'] = $item->lokasi_detail;
            $data['pemberi'] = [
                'name' => $item->pemberi?->name,
                'no_whatsapp' => $item->pemberi?->no_whatsapp,
            ];
            $data['tautan_whatsapp'] = $this->tautanWhatsapp($claim);
        }

        return $data;
    }
}