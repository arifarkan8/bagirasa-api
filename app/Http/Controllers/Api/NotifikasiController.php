<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notifikasi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotifikasiController extends Controller
{
    /**
     * Daftar notifikasi milik pengguna yang login (ikon lonceng).
     * Filter opsional: ?belum_dibaca=1
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'belum_dibaca' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Notifikasi::where('user_id', $request->user()->id);

        if ($request->boolean('belum_dibaca')) {
            $query->belumDibaca();
        }

        $hasil = $query->latest()
            ->paginate($request->integer('per_page', 15))
            ->through(fn (Notifikasi $n) => $this->format($n));

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'jumlah_belum_dibaca' => Notifikasi::where('user_id', $request->user()->id)->belumDibaca()->count(),
            'notifications' => $hasil,
        ]);
    }

    /**
     * Angka merah di ikon lonceng.
     */
    public function jumlahBelumDibaca(Request $request): JsonResponse
    {
        $jumlah = Notifikasi::where('user_id', $request->user()->id)
            ->belumDibaca()
            ->count();

        return response()->json(['jumlah_belum_dibaca' => $jumlah]);
    }

    /**
     * Tandai satu notifikasi sebagai dibaca. Hanya milik sendiri.
     */
    public function tandaiDibaca(Request $request, int $id): JsonResponse
    {
        $notifikasi = Notifikasi::where('user_id', $request->user()->id)
            ->whereKey($id)
            ->first();

        if (! $notifikasi) {
            return response()->json(['message' => 'Notifikasi tidak ditemukan.'], 404);
        }

        $notifikasi->tandaiDibaca();

        return response()->json([
            'message' => 'Notifikasi ditandai dibaca.',
            'notification' => $this->format($notifikasi->fresh()),
        ]);
    }

    /**
     * Tandai semua notifikasi milik sendiri sebagai dibaca.
     */
    public function tandaiSemuaDibaca(Request $request): JsonResponse
    {
        $diubah = Notifikasi::where('user_id', $request->user()->id)
            ->belumDibaca()
            ->update([
                'dibaca' => true,
                'dibaca_at' => now(),
            ]);

        return response()->json([
            'message' => 'Semua notifikasi ditandai dibaca.',
            'jumlah_diubah' => $diubah,
        ]);
    }

    private function format(Notifikasi $n): array
    {
        return [
            'id' => $n->id,
            'tipe' => $n->tipe,
            'judul' => $n->judul,
            'isi' => $n->isi,
            'item_id' => $n->item_id,
            'claim_id' => $n->claim_id,
            'dibaca' => $n->dibaca,
            'dibaca_at' => $n->dibaca_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}