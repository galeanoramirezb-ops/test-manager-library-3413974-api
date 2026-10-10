<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function mostBorrowed(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');

        if (!$from || !$to) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Los parámetros "from" y "to" son obligatorios.',
                [['field' => 'from/to', 'issue' => 'fechas requeridas']],
                422
            );
        }

        try {
            $fromDate = Carbon::parse($from)->startOfDay();
            $toDate = Carbon::parse($to)->endOfDay();
        } catch (\Exception $e) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Formato de fecha inválido. Utilice formato ISO (YYYY-MM-DD).',
                [],
                422
            );
        }

        if ($fromDate->greaterThan($toDate)) {
            return ApiResponse::error(
                'INVALID_DATE_RANGE',
                'La fecha inicial no puede ser posterior a la fecha final.',
                [['field' => 'from', 'issue' => 'rango invertido']],
                422
            );
        }

        $results = DB::table('library.loan_item as item')
            ->join('library.loan as l', 'item.loan_id', '=', 'l.id')
            ->where('l.loaned_at', '>=', $fromDate)
            ->where('l.loaned_at', '<=', $toDate)
            ->select(
                'item.book_id',
                'item.book_title',
                'item.category_name',
                DB::raw('COUNT(*) as times_borrowed')
            )
            ->groupBy('item.book_id', 'item.book_title', 'item.category_name')
            ->orderByDesc('times_borrowed')
            ->orderBy('item.book_title', 'asc')
            ->get();

        $rows = $results->map(function ($r) {
            return [
                'book_id' => $r->book_id,
                'book_title' => $r->book_title,
                'category_name' => $r->category_name,
                'times_borrowed' => (int) $r->times_borrowed,
            ];
        })->all();

        return response()->json([
            'from' => Carbon::parse($from)->toDateString(),
            'to' => Carbon::parse($to)->toDateString(),
            'rows' => $rows,
        ], 200);
    }
}

