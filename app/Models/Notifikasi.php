<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notifikasi extends Model
{
    use HasFactory;

    protected $table = 'notifications';

    public const TIPE_ITEM_DIKLAIM = 'item_diklaim';
    public const TIPE_KLAIM_DIBATALKAN = 'klaim_dibatalkan';
    public const TIPE_KLAIM_SELESAI = 'klaim_selesai';
    public const TIPE_LOCK_HAMPIR_HABIS = 'lock_hampir_habis';
    public const TIPE_KLAIM_KEDALUWARSA = 'klaim_kedaluwarsa';

    protected $fillable = [
        'user_id',
        'tipe',
        'judul',
        'isi',
        'item_id',
        'claim_id',
        'dibaca',
        'dibaca_at',
    ];

    protected function casts(): array
    {
        return [
            'dibaca' => 'boolean',
            'dibaca_at' => 'datetime',
        ];
    }

    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function scopeBelumDibaca(Builder $query): Builder
    {
        return $query->where('dibaca', false);
    }

    public function tandaiDibaca(): void
    {
        if (! $this->dibaca) {
            $this->update([
                'dibaca' => true,
                'dibaca_at' => now(),
            ]);
        }
    }

    /**
     * Pembantu singkat untuk membuat notifikasi dari controller atau scheduler.
     */
    public static function kirim(
        int $userId,
        string $tipe,
        string $judul,
        string $isi,
        ?int $itemId = null,
        ?int $claimId = null
    ): self {
        return static::create([
            'user_id' => $userId,
            'tipe' => $tipe,
            'judul' => $judul,
            'isi' => $isi,
            'item_id' => $itemId,
            'claim_id' => $claimId,
        ]);
    }
}