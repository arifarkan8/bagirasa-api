<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Claim;
use App\Models\Item;
use App\Models\Notifikasi;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RapikanKlaimDanItem extends Command
{
    protected $signature = 'bagirasa:rapikan';

    protected $description = 'Merapikan klaim yang lock-nya habis, item yang kedaluwarsa, dan mengirim peringatan lock hampir habis';

    /**
     * Batas klaim hangus berturut-turut dan lama pembatasan akun.
     * Nilainya disamakan dengan ClaimController.
     */
    private const BATAS_NO_SHOW = 3;
    private const DURASI_PEMBATASAN_JAM = 24;

    /**
     * Peringatan dikirim saat sisa lock tinggal sekian menit.
     */
    private const MENIT_PERINGATAN = 5;

    public function handle(): int
    {
        $klaim = $this->rapikanKlaimHabis();
        $item = $this->rapikanItemKedaluwarsa();
        $peringatan = $this->kirimPeringatanLock();

        $this->info("Selesai. Klaim hangus: {$klaim}, item kedaluwarsa: {$item}, peringatan terkirim: {$peringatan}.");

        return self::SUCCESS;
    }

    /**
     * Klaim active yang locked_until-nya lewat dijadikan expired,
     * dihitung sebagai no-show, lalu itemnya dikembalikan.
     */
    private function rapikanKlaimHabis(): int
    {
        $jumlah = 0;
        $ids = Claim::query()->lockHabis()->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$jumlah) {
                $claim = Claim::whereKey($id)->lockForUpdate()->first();

                // Dicek ulang karena bisa saja sudah dibatalkan atau selesai
                // di antara pengambilan daftar dan penguncian baris.
                if (! $claim || ! $claim->lockHabis()) {
                    return;
                }

                $item = Item::withTrashed()->whereKey($claim->item_id)->lockForUpdate()->first();

                $claim->update(['status' => Claim::STATUS_EXPIRED]);
                $this->catatNoShow($claim->user_id);

                if ($item) {
                    $this->kembalikanItem($item);

                    Notifikasi::kirim(
                        $claim->user_id,
                        Notifikasi::TIPE_KLAIM_KEDALUWARSA,
                        'Klaim kedaluwarsa',
                        "Waktu klaim atas \"{$item->judul}\" habis.",
                        $item->id,
                        $claim->id
                    );
                }

                $jumlah++;
            });
        }

        return $jumlah;
    }

    /**
     * Item available atau locked yang sudah melewati expires_at dijadikan expired.
     * Item tanpa expires_at (pakaian dan barang tanpa batas) tidak disentuh.
     */
    private function rapikanItemKedaluwarsa(): int
    {
        return Item::whereIn('status', [Item::STATUS_AVAILABLE, Item::STATUS_LOCKED])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => Item::STATUS_EXPIRED]);
    }

    /**
     * Notifikasi sekali per klaim saat sisa lock tinggal beberapa menit.
     */
    private function kirimPeringatanLock(): int
    {
        $jumlah = 0;

        $klaim = Claim::with('item')
            ->where('status', Claim::STATUS_ACTIVE)
            ->where('locked_until', '>', now())
            ->where('locked_until', '<=', now()->addMinutes(self::MENIT_PERINGATAN))
            ->get();

        foreach ($klaim as $c) {
            $sudahDikirim = Notifikasi::where('claim_id', $c->id)
                ->where('tipe', Notifikasi::TIPE_LOCK_HAMPIR_HABIS)
                ->exists();

            if ($sudahDikirim || ! $c->item) {
                continue;
            }

            Notifikasi::kirim(
                $c->user_id,
                Notifikasi::TIPE_LOCK_HAMPIR_HABIS,
                'Waktu klaim hampir habis',
                "Klaim atas \"{$c->item->judul}\" akan hangus sebentar lagi. Segera ambil atau batalkan.",
                $c->item_id,
                $c->id
            );

            $jumlah++;
        }

        return $jumlah;
    }

    /**
     * Item kembali tersedia bila belum kedaluwarsa, selain itu expired.
     * Item yang sudah selesai, dibatalkan, atau disembunyikan tidak diubah.
     */
    private function kembalikanItem(Item $item): void
    {
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
}