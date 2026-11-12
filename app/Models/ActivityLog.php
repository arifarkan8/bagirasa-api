<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use HasFactory;

    /**
     * Tabel ini hanya punya created_at, tanpa updated_at.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'aksi',
        'target_tipe',
        'target_id',
        'detail',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Pembantu singkat untuk mencatat tindakan dari controller atau scheduler.
     * Untuk tindakan otomatis (scheduler), isi $userId dengan null.
     */
    public static function catat(
        string $aksi,
        ?int $userId = null,
        ?string $targetTipe = null,
        ?int $targetId = null,
        ?array $detail = null,
        ?string $ip = null
    ): self {
        return static::create([
            'user_id' => $userId,
            'aksi' => $aksi,
            'target_tipe' => $targetTipe,
            'target_id' => $targetId,
            'detail' => $detail,
            'ip_address' => $ip,
        ]);
    }
}