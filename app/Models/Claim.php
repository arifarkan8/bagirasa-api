<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Claim extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    /**
     * Durasi lock default (menit) dan batas klaim aktif per pengguna.
     */
    public const DURASI_LOCK_MENIT = 30;
    public const MAKS_KLAIM_AKTIF = 2;

    protected $fillable = [
        'item_id',
        'user_id',
        'kode_klaim',
        'locked_until',
        'status',
        'dikonfirmasi_pemberi_at',
        'dikonfirmasi_penerima_at',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'locked_until' => 'datetime',
            'dikonfirmasi_pemberi_at' => 'datetime',
            'dikonfirmasi_penerima_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Penerima (pengguna yang mengklaim).
     */
    public function penerima(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sudahAktif(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Lock sudah lewat waktu tapi status masih active (belum dirapikan scheduler).
     */
    public function lockHabis(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->locked_until->isPast();
    }

    /**
     * Sisa detik lock, 0 jika sudah habis. Dipakai frontend untuk bar waktu.
     */
    public function sisaDetik(): int
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return 0;
        }

        return max(0, now()->diffInSeconds($this->locked_until, false));
    }

    /**
     * Scope: klaim active yang lock-nya sudah lewat. Dipakai scheduler.
     */
    public function scopeLockHabis(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where('locked_until', '<', now());
    }

    /**
     * Scope: klaim milik satu pengguna yang masih berjalan.
     */
    public function scopeAktifMilik(Builder $query, int $userId): Builder
    {
        return $query
            ->where('user_id', $userId)
            ->where('status', self::STATUS_ACTIVE)
            ->where('locked_until', '>=', now());
    }

    /**
     * Membuat kode klaim pendek, contoh BR-7K2Q.
     * Huruf yang mirip (O, 0, I, 1) dihindari agar mudah dibaca.
     */
    public static function buatKode(): string
    {
        $karakter = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $kode = 'BR-';
            for ($i = 0; $i < 4; $i++) {
                $kode .= $karakter[random_int(0, strlen($karakter) - 1)];
            }
        } while (static::where('kode_klaim', $kode)->exists());

        return $kode;
    }
}