<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_DITERIMA = 'diterima';
    public const STATUS_DITOLAK = 'ditolak';

    public const ALASAN = [
        'basi',
        'tidak_sesuai_foto',
        'penipuan',
        'konten_tidak_pantas',
        'lainnya',
    ];

    protected $fillable = [
        'item_id',
        'user_id',
        'alasan',
        'keterangan',
        'status_tinjauan',
        'ditinjau_oleh',
        'catatan_admin',
        'ditinjau_at',
    ];

    protected function casts(): array
    {
        return [
            'ditinjau_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    /**
     * Pengguna yang melapor.
     */
    public function pelapor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Admin yang meninjau laporan.
     */
    public function peninjau(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ditinjau_oleh');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status_tinjauan', self::STATUS_PENDING);
    }
}