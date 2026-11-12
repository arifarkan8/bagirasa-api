<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use HasFactory, SoftDeletes;

    public const JENIS_MAKANAN = 'makanan';
    public const JENIS_PAKAIAN = 'pakaian';
    public const JENIS_BARANG = 'barang';

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_LOCKED = 'locked';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_HIDDEN = 'hidden';

    protected $fillable = [
        'user_id',
        'judul',
        'deskripsi',
        'jenis',
        'kategori',
        'kondisi',
        'jumlah',
        'foto',
        'area',
        'lokasi_detail',
        'waktu_dibuat_makanan',
        'expires_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'jumlah' => 'integer',
            'waktu_dibuat_makanan' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Pemberi (pemilik postingan).
     */
    public function pemberi(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    /**
     * Klaim yang sedang aktif (maksimal satu per item).
     */
    public function klaimAktif(): HasOne
    {
        return $this->hasOne(Claim::class)->where('status', Claim::STATUS_ACTIVE);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /**
     * Apakah item sudah lewat batas kelayakan.
     * Item tanpa expires_at (pakaian dan barang) tidak pernah kedaluwarsa otomatis.
     */
    public function sudahKedaluwarsa(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Status efektif dihitung di server pada setiap request.
     * Dipakai agar item kedaluwarsa tidak bisa diklaim walau scheduler belum jalan.
     */
    public function statusEfektif(): string
    {
        if (in_array($this->status, [self::STATUS_AVAILABLE, self::STATUS_LOCKED], true)
            && $this->sudahKedaluwarsa()) {
            return self::STATUS_EXPIRED;
        }

        return $this->status;
    }

    public function bisaDiklaim(): bool
    {
        return $this->statusEfektif() === self::STATUS_AVAILABLE;
    }

    /**
     * Scope: item yang masih tampil di beranda (belum kedaluwarsa, tidak disembunyikan).
     */
    public function scopeTampil(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [self::STATUS_AVAILABLE, self::STATUS_LOCKED])
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope: urutan paling cepat kedaluwarsa lebih dulu, item tanpa batas waktu di akhir.
     */
    public function scopeUrutMendesak(Builder $query): Builder
    {
        return $query
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderByDesc('created_at');
    }
}