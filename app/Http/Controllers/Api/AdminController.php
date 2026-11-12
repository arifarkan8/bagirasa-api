<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Claim;
use App\Models\Item;
use App\Models\Notifikasi;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    /**
     * Antrean laporan. Default menampilkan yang masih pending.
     * Filter opsional: ?status=pending|diterima|ditolak
     */
    public function laporan(Request $request): JsonResponse
    {
        $this->pastikanAdmin($request);

        $request->validate([
            'status' => ['nullable', Rule::in([
                Report::STATUS_PENDING,
                Report::STATUS_DITERIMA,
                Report::STATUS_DITOLAK,
            ])],
        ]);

        $hasil = Report::with(['item', 'pelapor:id,name', 'peninjau:id,name'])
            ->where('status_tinjauan', $request->input('status', Report::STATUS_PENDING))
            ->oldest()
            ->paginate(15)
            ->through(fn (Report $r) => $this->formatLaporan($r));

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'reports' => $hasil,
        ]);
    }

    /**
     * Meninjau satu laporan. Bila diterima, admin boleh sekaligus
     * menyembunyikan item yang dilaporkan.
     */
    public function tinjauLaporan(Request $request, int $id): JsonResponse
    {
        $this->pastikanAdmin($request);
        $admin = $request->user();

        $data = $request->validate([
            'keputusan' => ['required', Rule::in([Report::STATUS_DITERIMA, Report::STATUS_DITOLAK])],
            'catatan_admin' => ['nullable', 'string', 'max:500'],
            'sembunyikan_item' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($id, $data, $admin, $request) {
            $report = Report::whereKey($id)->lockForUpdate()->first();

            if (! $report) {
                abort(404, 'Laporan tidak ditemukan.');
            }

            if ($report->status_tinjauan !== Report::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'laporan' => 'Laporan ini sudah ditinjau.',
                ]);
            }

            $report->update([
                'status_tinjauan' => $data['keputusan'],
                'catatan_admin' => $data['catatan_admin'] ?? null,
                'ditinjau_oleh' => $admin->id,
                'ditinjau_at' => now(),
            ]);

            ActivityLog::catat(
                'laporan_ditinjau',
                $admin->id,
                'report',
                $report->id,
                ['keputusan' => $data['keputusan']],
                $request->ip()
            );

            if ($data['keputusan'] === Report::STATUS_DITERIMA && $request->boolean('sembunyikan_item')) {
                $this->sembunyikan($report->item_id, $admin, $data['catatan_admin'] ?? null, $request->ip());
            }
        });

        return response()->json(['message' => 'Laporan ditinjau.']);
    }

    /**
     * Menyembunyikan item secara langsung tanpa lewat laporan.
     */
    public function sembunyikanItem(Request $request, int $itemId): JsonResponse
    {
        $this->pastikanAdmin($request);

        $data = $request->validate([
            'alasan' => ['nullable', 'string', 'max:500'],
        ]);

        $berhasil = DB::transaction(
            fn () => $this->sembunyikan($itemId, $request->user(), $data['alasan'] ?? null, $request->ip())
        );

        if (! $berhasil) {
            return response()->json(['message' => 'Item tidak ditemukan atau sudah disembunyikan.'], 404);
        }

        return response()->json(['message' => 'Item disembunyikan.']);
    }

    /**
     * Memulihkan item yang disembunyikan. Kembali tersedia bila belum
     * kedaluwarsa, selain itu menjadi expired.
     */
    public function pulihkanItem(Request $request, int $itemId): JsonResponse
    {
        $this->pastikanAdmin($request);
        $admin = $request->user();

        $statusBaru = DB::transaction(function () use ($itemId, $admin, $request) {
            $item = Item::whereKey($itemId)->lockForUpdate()->first();

            if (! $item) {
                abort(404, 'Item tidak ditemukan.');
            }

            if ($item->status !== Item::STATUS_HIDDEN) {
                throw ValidationException::withMessages([
                    'item' => 'Item ini tidak sedang disembunyikan.',
                ]);
            }

            $status = $item->sudahKedaluwarsa() ? Item::STATUS_EXPIRED : Item::STATUS_AVAILABLE;
            $item->update(['status' => $status]);

            ActivityLog::catat('item_dipulihkan', $admin->id, 'item', $item->id, ['status_baru' => $status], $request->ip());

            return $status;
        });

        return response()->json([
            'message' => 'Item dipulihkan.',
            'status' => $statusBaru,
        ]);
    }

    /**
     * Daftar akun. Filter opsional: ?status_akun=aktif|dibatasi|diblokir dan ?q=kata
     */
    public function akun(Request $request): JsonResponse
    {
        $this->pastikanAdmin($request);

        $request->validate([
            'status_akun' => ['nullable', Rule::in(['aktif', 'dibatasi', 'diblokir'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = User::query();

        if ($request->filled('status_akun')) {
            $query->where('status_akun', $request->input('status_akun'));
        }

        if ($request->filled('q')) {
            $kata = $request->input('q');
            $query->where(function ($q) use ($kata) {
                $q->where('name', 'like', "%{$kata}%")
                    ->orWhere('email', 'like', "%{$kata}%");
            });
        }

        $hasil = $query->latest()
            ->paginate(20)
            ->through(fn (User $u) => $this->formatAkun($u));

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'users' => $hasil,
        ]);
    }

    /**
     * Memblokir akun. Semua token login akun itu dicabut seketika.
     */
    public function blokirAkun(Request $request, int $userId): JsonResponse
    {
        $this->pastikanAdmin($request);
        $admin = $request->user();

        $data = $request->validate([
            'alasan' => ['nullable', 'string', 'max:500'],
        ]);

        $user = User::find($userId);

        if (! $user) {
            return response()->json(['message' => 'Akun tidak ditemukan.'], 404);
        }

        if ($user->id === $admin->id) {
            return response()->json(['message' => 'Anda tidak bisa memblokir akun sendiri.'], 422);
        }

        if ($user->isAdmin()) {
            return response()->json(['message' => 'Akun admin tidak bisa diblokir.'], 422);
        }

        if ($user->status_akun === 'diblokir') {
            return response()->json(['message' => 'Akun ini sudah diblokir.'], 422);
        }

        $user->update([
            'status_akun' => 'diblokir',
            'dibatasi_sampai' => null,
        ]);

        $user->tokens()->delete();

        ActivityLog::catat(
            'akun_diblokir',
            $admin->id,
            'user',
            $user->id,
            ['alasan' => $data['alasan'] ?? null],
            $request->ip()
        );

        return response()->json(['message' => 'Akun diblokir.']);
    }

    /**
     * Memulihkan akun yang diblokir atau dibatasi.
     */
    public function pulihkanAkun(Request $request, int $userId): JsonResponse
    {
        $this->pastikanAdmin($request);
        $admin = $request->user();

        $user = User::find($userId);

        if (! $user) {
            return response()->json(['message' => 'Akun tidak ditemukan.'], 404);
        }

        if ($user->status_akun === 'aktif') {
            return response()->json(['message' => 'Akun ini sudah aktif.'], 422);
        }

        $user->update([
            'status_akun' => 'aktif',
            'dibatasi_sampai' => null,
            'no_show_count' => 0,
        ]);

        ActivityLog::catat('akun_dipulihkan', $admin->id, 'user', $user->id, null, $request->ip());

        return response()->json(['message' => 'Akun dipulihkan.']);
    }

    /**
     * Log tindakan. Filter opsional: ?aksi=klaim_dibuat dan ?user_id=1
     */
    public function log(Request $request): JsonResponse
    {
        $this->pastikanAdmin($request);

        $request->validate([
            'aksi' => ['nullable', 'string', 'max:50'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $query = ActivityLog::with('pengguna:id,name');

        if ($request->filled('aksi')) {
            $query->where('aksi', $request->input('aksi'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        $hasil = $query->latest('created_at')
            ->latest('id')
            ->paginate(25)
            ->through(fn (ActivityLog $l) => [
                'id' => $l->id,
                'aksi' => $l->aksi,
                'user' => $l->pengguna ? ['id' => $l->pengguna->id, 'name' => $l->pengguna->name] : null,
                'target_tipe' => $l->target_tipe,
                'target_id' => $l->target_id,
                'detail' => $l->detail,
                'ip_address' => $l->ip_address,
                'created_at' => $l->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'logs' => $hasil,
        ]);
    }

    /**
     * Pengecekan peran admin. Dipanggil di awal setiap method.
     */
    private function pastikanAdmin(Request $request): void
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Hanya admin yang boleh mengakses ini.');
        }
    }

    /**
     * Menyembunyikan item. Klaim aktif pada item itu dibatalkan tanpa
     * penalti no-show. Harus dipanggil di dalam transaction.
     * Mengembalikan false bila item tidak ada atau sudah tersembunyi.
     */
    private function sembunyikan(int $itemId, User $admin, ?string $alasan, ?string $ip): bool
    {
        $item = Item::whereKey($itemId)->lockForUpdate()->first();

        if (! $item || $item->status === Item::STATUS_HIDDEN) {
            return false;
        }

        $klaimAktif = Claim::where('item_id', $item->id)
            ->where('status', Claim::STATUS_ACTIVE)
            ->get();

        foreach ($klaimAktif as $klaim) {
            $klaim->update([
                'status' => Claim::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

            Notifikasi::kirim(
                $klaim->user_id,
                Notifikasi::TIPE_KLAIM_DIBATALKAN,
                'Klaim dibatalkan admin',
                "Klaim atas \"{$item->judul}\" dibatalkan karena postingan disembunyikan admin.",
                $item->id,
                $klaim->id
            );
        }

        $item->update(['status' => Item::STATUS_HIDDEN]);

        Notifikasi::kirim(
            $item->user_id,
            'item_disembunyikan',
            'Postingan disembunyikan',
            "Postingan \"{$item->judul}\" disembunyikan oleh admin.",
            $item->id
        );

        ActivityLog::catat(
            'item_disembunyikan',
            $admin->id,
            'item',
            $item->id,
            ['alasan' => $alasan, 'klaim_dibatalkan' => $klaimAktif->count()],
            $ip
        );

        return true;
    }

    private function formatLaporan(Report $r): array
    {
        return [
            'id' => $r->id,
            'alasan' => $r->alasan,
            'keterangan' => $r->keterangan,
            'status_tinjauan' => $r->status_tinjauan,
            'catatan_admin' => $r->catatan_admin,
            'ditinjau_at' => $r->ditinjau_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
            'item' => $r->item ? [
                'id' => $r->item->id,
                'judul' => $r->item->judul,
                'jenis' => $r->item->jenis,
                'status' => $r->item->status,
            ] : null,
            'pelapor' => $r->pelapor ? ['id' => $r->pelapor->id, 'name' => $r->pelapor->name] : null,
            'peninjau' => $r->peninjau ? ['id' => $r->peninjau->id, 'name' => $r->peninjau->name] : null,
        ];
    }

    private function formatAkun(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'no_whatsapp' => $u->no_whatsapp,
            'area' => $u->area,
            'role' => $u->role,
            'status_akun' => $u->status_akun,
            'no_show_count' => $u->no_show_count,
            'dibatasi_sampai' => $u->dibatasi_sampai?->toIso8601String(),
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}