<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Book;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoanController extends Controller
{
    private function formatLoan(Loan $loan): array
    {
        $items = $loan->relationLoaded('items') ? $loan->items : $loan->items()->get();

        return [
            'id' => $loan->id,
            'member_id' => $loan->member_id,
            'loaned_at' => $loan->loaned_at ? Carbon::parse($loan->loaned_at)->toISOString() : null,
            'status' => $loan->calculated_status,
            'items' => $items->map(function (LoanItem $item) {
                return [
                    'id' => $item->id,
                    'book_id' => $item->book_id,
                    'book_title' => $item->book_title,
                    'isbn' => $item->isbn,
                    'category_name' => $item->category_name,
                    'due_at' => $item->due_at ? Carbon::parse($item->due_at)->toISOString() : null,
                    'returned_at' => $item->returned_at ? Carbon::parse($item->returned_at)->toISOString() : null,
                ];
            })->all(),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');
        $memberId = $request->input('member_id');
        $rawItems = $request->input('items', []);

        if (!is_array($rawItems) || count($rawItems) < 1 || count($rawItems) > 3) {
            return ApiResponse::error(
                'MEMBER_LIMIT_EXCEEDED',
                'Un préstamo debe contener entre 1 y 3 libros.',
                [['field' => 'items', 'issue' => 'debe contener de 1 a 3 elementos']],
                422
            );
        }

        $bookIds = [];
        foreach ($rawItems as $idx => $it) {
            $bId = $it['book_id'] ?? null;
            if (!$bId) {
                return ApiResponse::error(
                    'VALIDATION_ERROR',
                    "El elemento en la posición {$idx} no contiene 'book_id'.",
                    [['field' => "items[{$idx}].book_id", 'issue' => 'requerido']],
                    422
                );
            }
            if (in_array($bId, $bookIds, true)) {
                return ApiResponse::error(
                    'DUPLICATE_BOOK_IN_LOAN',
                    'No se puede prestar el mismo libro más de una vez en un préstamo.',
                    [['field' => "items[{$idx}].book_id", 'issue' => 'libro duplicado en la solicitud']],
                    422
                );
            }
            $bookIds[] = $bId;
        }

        $member = Member::find($memberId);
        if (!$member) {
            return ApiResponse::error('NOT_FOUND', 'El socio especificado no existe.', [], 404);
        }

        // Verificar libros sin devolver del socio
        $activeLoansCount = LoanItem::whereHas('loan', function ($q) use ($memberId) {
            $q->where('member_id', $memberId);
        })->whereNull('returned_at')->count();

        if ($activeLoansCount + count($bookIds) > 3) {
            return ApiResponse::error(
                'MEMBER_LIMIT_EXCEEDED',
                "El socio ya tiene {$activeLoansCount} libros sin devolver. Con esta solicitud superaría el límite de 3.",
                [['field' => 'member_id', 'issue' => 'límite de 3 libros superado']],
                422
            );
        }

        // Instante del préstamo (permite parámetro para sembrado o por defecto now en UTC)
        $loanedAt = $request->input('loaned_at') ? Carbon::parse($request->input('loaned_at')) : Carbon::now('UTC');

        // Verificar si el socio tiene préstamos vencidos
        $now = Carbon::now('UTC');
        $hasOverdue = LoanItem::whereHas('loan', function ($q) use ($memberId) {
            $q->where('member_id', $memberId);
        })->whereNull('returned_at')->where('due_at', '<', $now)->exists();

        if ($hasOverdue) {
            return ApiResponse::error(
                'MEMBER_HAS_OVERDUE',
                'El socio tiene préstamos vencidos pendientes de devolución.',
                [['field' => 'member_id', 'issue' => 'socio en mora']],
                409
            );
        }

        return DB::transaction(function () use ($user, $memberId, $bookIds, $loanedAt) {
            $books = [];
            foreach ($bookIds as $bId) {
                $book = Book::with('category')->whereNull('deleted_at')->lockForUpdate()->find($bId);
                if (!$book) {
                    return ApiResponse::error('NOT_FOUND', "El libro con ID '{$bId}' no existe o fue dado de baja.", [], 404);
                }
                if ($book->available_copies < 1) {
                    return ApiResponse::error(
                        'BOOK_NOT_AVAILABLE',
                        "No quedan ejemplares disponibles del libro '{$book->title}'.",
                        [['field' => 'items', 'issue' => 'sin ejemplares disponibles']],
                        409
                    );
                }
                $books[] = $book;
            }

            // Descontar ejemplares
            foreach ($books as $book) {
                $book->available_copies -= 1;
                $book->save();
            }

            $loan = Loan::create([
                'id' => (string) Str::uuid(),
                'member_id' => $memberId,
                'loaned_by_user_id' => $user->id,
                'loaned_at' => $loanedAt,
            ]);

            $dueAt = $loanedAt->copy()->addDays(14);
            $items = [];

            foreach ($books as $book) {
                $item = LoanItem::create([
                    'id' => (string) Str::uuid(),
                    'loan_id' => $loan->id,
                    'book_id' => $book->id,
                    'book_title' => $book->title,
                    'isbn' => $book->isbn,
                    'category_name' => $book->category->name ?? 'General',
                    'due_at' => $dueAt,
                    'returned_at' => null,
                ]);
                $items[] = $item;
            }

            $loan->setRelation('items', collect($items));

            return response()->json($this->formatLoan($loan), 201);
        });
    }

    public function index(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $status = $request->query('status');
        $memberId = $request->query('member_id');
        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('size', 20)));

        $query = Loan::with(['items', 'member'])->orderBy('loaned_at', 'desc');

        if ($memberId) {
            $query->where('member_id', $memberId);
        }

        if ($from) {
            $query->where('loaned_at', '>=', Carbon::parse($from)->startOfDay());
        }

        if ($to) {
            $query->where('loaned_at', '<=', Carbon::parse($to)->endOfDay());
        }

        $allLoans = $query->get();

        if ($status && in_array($status, ['open', 'overdue', 'closed'], true)) {
            $allLoans = $allLoans->filter(fn (Loan $l) => $l->calculated_status === $status)->values();
        }

        $total = $allLoans->count();
        $paged = $allLoans->slice(($page - 1) * $size, $size)->values();

        $items = $paged->map(fn (Loan $l) => $this->formatLoan($l))->all();

        return ApiResponse::paginated($items, $page, $size, $total);
    }

    public function show(string $id): JsonResponse
    {
        $loan = Loan::with('items')->find($id);
        if (!$loan) {
            return ApiResponse::error('NOT_FOUND', 'El préstamo solicitado no existe.', [], 404);
        }

        return response()->json($this->formatLoan($loan), 200);
    }

    public function returnItem(string $loanId, string $itemId): JsonResponse
    {
        $item = LoanItem::where('loan_id', $loanId)->where('id', $itemId)->first();

        if (!$item) {
            return ApiResponse::error('NOT_FOUND', 'La línea de préstamo no existe.', [], 404);
        }

        if ($item->returned_at !== null) {
            return ApiResponse::error(
                'ITEM_ALREADY_RETURNED',
                'El ejemplar ya fue devuelto previamente.',
                [['field' => 'item_id', 'issue' => 'ya devuelto']],
                409
            );
        }

        return DB::transaction(function () use ($item) {
            $now = Carbon::now('UTC');
            $item->returned_at = $now;
            $item->save();

            // Reabastecer ejemplar en el libro si existe
            $book = Book::lockForUpdate()->find($item->book_id);
            if ($book) {
                if ($book->available_copies < $book->total_copies) {
                    $book->available_copies += 1;
                    $book->save();
                }
            }

            return response()->json([
                'id' => $item->id,
                'loan_id' => $item->loan_id,
                'book_id' => $item->book_id,
                'book_title' => $item->book_title,
                'isbn' => $item->isbn,
                'category_name' => $item->category_name,
                'due_at' => $item->due_at ? Carbon::parse($item->due_at)->toISOString() : null,
                'returned_at' => Carbon::parse($item->returned_at)->toISOString(),
            ], 200);
        });
    }

    public function overdue(): JsonResponse
    {
        $now = Carbon::now('UTC');

        $overdueItems = LoanItem::with('loan.member')
            ->whereNull('returned_at')
            ->where('due_at', '<', $now)
            ->get();

        $rows = $overdueItems->map(function (LoanItem $item) use ($now) {
            $due = Carbon::parse($item->due_at);
            $daysLate = max(1, (int) floor($due->diffInSeconds($now) / 86400));

            return [
                'loan_id' => $item->loan_id,
                'item_id' => $item->id,
                'book_title' => $item->book_title,
                'member_name' => $item->loan->member->full_name ?? 'Desconocido',
                'due_at' => $due->toISOString(),
                'days_late' => $daysLate,
            ];
        })->sortByDesc('days_late')->values()->all();

        return response()->json([
            'items' => $rows,
        ], 200);
    }
}
