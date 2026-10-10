<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\LoanItem;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MemberController extends Controller
{
    private function getActiveLoansCount(string $memberId): int
    {
        return LoanItem::whereHas('loan', function ($q) use ($memberId) {
            $q->where('member_id', $memberId);
        })->whereNull('returned_at')->count();
    }

    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('size', 20)));

        $query = Member::query();

        if ($q !== '') {
            $query->where(function ($m) use ($q) {
                $m->where('full_name', 'ILIKE', "%{$q}%")
                  ->orWhere('document_number', 'ILIKE', "%{$q}%");
            });
        }

        $total = $query->count();
        $members = $query->orderBy('full_name', 'asc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        $items = $members->map(function (Member $m) {
            return [
                'id' => $m->id,
                'full_name' => $m->full_name,
                'document_number' => $m->document_number,
                'active_loans' => $this->getActiveLoansCount($m->id),
            ];
        })->all();

        return ApiResponse::paginated($items, $page, $size, $total);
    }

    public function show(string $id): JsonResponse
    {
        $member = Member::find($id);
        if (!$member) {
            return ApiResponse::error('NOT_FOUND', 'El socio solicitado no existe.', [], 404);
        }

        return response()->json([
            'id' => $member->id,
            'full_name' => $member->full_name,
            'document_number' => $member->document_number,
            'active_loans' => $this->getActiveLoansCount($member->id),
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $fullName = trim((string) $request->input('full_name', ''));
        $documentNumber = trim((string) $request->input('document_number', ''));

        if ($fullName === '') {
            return ApiResponse::error('VALIDATION_ERROR', 'El nombre del socio es obligatorio.', [['field' => 'full_name', 'issue' => 'requerido']], 422);
        }

        if ($documentNumber === '') {
            return ApiResponse::error('VALIDATION_ERROR', 'El número de documento es obligatorio.', [['field' => 'document_number', 'issue' => 'requerido']], 422);
        }

        if (Member::where('document_number', $documentNumber)->exists()) {
            return ApiResponse::error(
                'DOCUMENT_TAKEN',
                "El documento '{$documentNumber}' ya pertenece a otro socio.",
                [['field' => 'document_number', 'issue' => 'duplicado']],
                409
            );
        }

        $member = Member::create([
            'id' => (string) Str::uuid(),
            'full_name' => $fullName,
            'document_number' => $documentNumber,
        ]);

        return response()->json([
            'id' => $member->id,
            'full_name' => $member->full_name,
            'document_number' => $member->document_number,
            'active_loans' => 0,
        ], 201);
    }
}

