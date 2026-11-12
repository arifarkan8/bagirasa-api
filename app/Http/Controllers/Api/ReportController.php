<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Item;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    /**
     * Melaporkan postingan bermasalah. Satu pengguna hanya bisa
     * melaporkan satu item satu kali.
     */
    public function store(Request $request, int $itemId): JsonResponse
    {
        $user = $request->user();

        $item = Item::find($itemId);

        if (! $item || $item->status === Item::STATUS_HIDDEN) {
            return response()->json(['message' => 'Item tidak ditemukan.'], 404);
        }

        if ($item->user_id === $user->id) {
            return response()->json([
                'message' => 'Anda tidak bisa melaporkan postingan milik sendiri.',
            ], 422);
        }

        $data = $request->validate([
            'alasan' => ['required', Rule::in(Report::ALASAN)],
            'keterangan' => ['nullable', 'string', 'max:500'],
        ], [
            'alasan.in' => 'Alasan laporan tidak valid.',
        ]);

        $sudahMelapor = Report::where('item_id', $item->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($sudahMelapor) {
            return response()->json([
                'message' => 'Anda sudah melaporkan postingan ini.',
            ], 422);
        }

        $report = Report::create([
            'item_id' => $item->id,
            'user_id' => $user->id,
            'alasan' => $data['alasan'],
            'keterangan' => $data['keterangan'] ?? null,
            'status_tinjauan' => Report::STATUS_PENDING,
        ]);

        ActivityLog::catat(
            'item_dilaporkan',
            $user->id,
            'item',
            $item->id,
            ['report_id' => $report->id, 'alasan' => $data['alasan']],
            $request->ip()
        );

        return response()->json([
            'message' => 'Laporan terkirim. Terima kasih, admin akan meninjaunya.',
            'report' => [
                'id' => $report->id,
                'item_id' => $report->item_id,
                'alasan' => $report->alasan,
                'status_tinjauan' => $report->status_tinjauan,
                'created_at' => $report->created_at?->toIso8601String(),
            ],
        ], 201);
    }
}