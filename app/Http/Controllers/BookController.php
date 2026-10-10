<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Book;
use App\Models\Category;
use App\Models\LoanItem;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class BookController extends Controller
{
    private function formatBook(Book $book): array
    {
        return [
            'id' => $book->id,
            'title' => $book->title,
            'author' => $book->author,
            'isbn' => $book->isbn,
            'category' => [
                'id' => $book->category->id ?? $book->category_id,
                'name' => $book->category->name ?? '',
            ],
            'total_copies' => (int) $book->total_copies,
            'available_copies' => (int) $book->available_copies,
            'cover_key' => $book->cover_key,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $categoryId = $request->query('category_id');
        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('size', 20)));

        $query = Book::with('category')->whereNull('deleted_at');

        if ($q !== '') {
            $query->where(function ($b) use ($q) {
                $b->where('title', 'ILIKE', "%{$q}%")
                  ->orWhere('author', 'ILIKE', "%{$q}%");
            });
        }

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $total = $query->count();
        $books = $query->orderBy('title', 'asc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        $items = $books->map(fn (Book $b) => $this->formatBook($b))->all();

        return ApiResponse::paginated($items, $page, $size, $total);
    }

    public function show(string $id): JsonResponse
    {
        $book = Book::with('category')->whereNull('deleted_at')->find($id);

        if (!$book) {
            return ApiResponse::error('NOT_FOUND', 'El libro solicitado no existe o fue dado de baja.', [], 404);
        }

        return response()->json($this->formatBook($book), 200);
    }

    public function store(Request $request): JsonResponse
    {
        $title = trim((string) $request->input('title', ''));
        $author = trim((string) $request->input('author', ''));
        $isbn = trim((string) $request->input('isbn', ''));
        $categoryId = $request->input('category_id');
        $totalCopies = (int) $request->input('total_copies', 0);

        if ($title === '') {
            return ApiResponse::error('VALIDATION_ERROR', 'El título es obligatorio.', [['field' => 'title', 'issue' => 'requerido']], 422);
        }

        if ($author === '') {
            return ApiResponse::error('VALIDATION_ERROR', 'El autor es obligatorio.', [['field' => 'author', 'issue' => 'requerido']], 422);
        }

        if (!preg_match('/^\d{13}$/', $isbn)) {
            return ApiResponse::error('VALIDATION_ERROR', 'El ISBN debe tener exactamente 13 dígitos numéricos.', [['field' => 'isbn', 'issue' => 'debe ser de 13 dígitos']], 422);
        }

        if ($totalCopies < 1) {
            return ApiResponse::error('VALIDATION_ERROR', 'El total de ejemplares debe ser al menos 1.', [['field' => 'total_copies', 'issue' => 'debe ser >= 1']], 422);
        }

        if (!Category::where('id', $categoryId)->exists()) {
            return ApiResponse::error('VALIDATION_ERROR', 'La categoría especificada no existe.', [['field' => 'category_id', 'issue' => 'no existe']], 422);
        }

        if (Book::whereNull('deleted_at')->where('isbn', $isbn)->exists()) {
            return ApiResponse::error('ISBN_TAKEN', "El ISBN {$isbn} ya se encuentra registrado en un libro activo.", [['field' => 'isbn', 'issue' => 'duplicado']], 409);
        }

        $book = Book::create([
            'id' => (string) Str::uuid(),
            'title' => $title,
            'author' => $author,
            'isbn' => $isbn,
            'category_id' => $categoryId,
            'total_copies' => $totalCopies,
            'available_copies' => $totalCopies,
            'cover_key' => null,
            'deleted_at' => null,
        ]);

        $book->load('category');

        return response()->json($this->formatBook($book), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $book = Book::with('category')->whereNull('deleted_at')->find($id);
        if (!$book) {
            return ApiResponse::error('NOT_FOUND', 'El libro no existe o fue dado de baja.', [], 404);
        }

        $title = trim((string) $request->input('title', ''));
        $author = trim((string) $request->input('author', ''));
        $categoryId = $request->input('category_id');
        $newTotal = (int) $request->input('total_copies', 0);

        if ($title === '') {
            return ApiResponse::error('VALIDATION_ERROR', 'El título es obligatorio.', [['field' => 'title', 'issue' => 'requerido']], 422);
        }

        if ($author === '') {
            return ApiResponse::error('VALIDATION_ERROR', 'El autor es obligatorio.', [['field' => 'author', 'issue' => 'requerido']], 422);
        }

        if ($newTotal < 1) {
            return ApiResponse::error('VALIDATION_ERROR', 'El total de ejemplares debe ser al menos 1.', [['field' => 'total_copies', 'issue' => 'debe ser >= 1']], 422);
        }

        if (!Category::where('id', $categoryId)->exists()) {
            return ApiResponse::error('VALIDATION_ERROR', 'La categoría especificada no existe.', [['field' => 'category_id', 'issue' => 'no existe']], 422);
        }

        $diff = $newTotal - $book->total_copies;
        $newAvailable = $book->available_copies + $diff;

        if ($newAvailable < 0) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'No se puede reducir el total de ejemplares por debajo de los ejemplares actualmente prestados.',
                [['field' => 'total_copies', 'issue' => 'disponibles quedarían negativos']],
                422
            );
        }

        $book->title = $title;
        $book->author = $author;
        $book->category_id = $categoryId;
        $book->total_copies = $newTotal;
        $book->available_copies = $newAvailable;
        $book->save();

        $book->load('category');

        return response()->json($this->formatBook($book), 200);
    }

    public function destroy(string $id): Response|JsonResponse
    {
        $book = Book::whereNull('deleted_at')->find($id);
        if (!$book) {
            return ApiResponse::error('NOT_FOUND', 'El libro no existe o ya fue dado de baja.', [], 404);
        }

        $hasOpenLoans = LoanItem::where('book_id', $id)->whereNull('returned_at')->exists();
        if ($hasOpenLoans) {
            return ApiResponse::error(
                'BOOK_HAS_OPEN_LOANS',
                'No se puede dar de baja un libro que tiene préstamos abiertos.',
                [['field' => 'id', 'issue' => 'tiene préstamos pendientes de devolución']],
                409
            );
        }

        $book->deleted_at = Carbon::now('UTC');
        $book->save();

        return response()->noContent();
    }

    public function uploadCover(Request $request, string $id): JsonResponse
    {
        $book = Book::with('category')->whereNull('deleted_at')->find($id);
        if (!$book) {
            return ApiResponse::error('NOT_FOUND', 'El libro no existe o fue dado de baja.', [], 404);
        }

        if (!$request->hasFile('file')) {
            return ApiResponse::error('VALIDATION_ERROR', 'Debe enviar un archivo de imagen en el campo "file".', [], 422);
        }

        $file = $request->file('file');
        if (!$file->isValid()) {
            return ApiResponse::error('VALIDATION_ERROR', 'Archivo de imagen no válido.', [], 422);
        }

        if ($file->getSize() > 2 * 1024 * 1024) {
            return ApiResponse::error('VALIDATION_ERROR', 'La imagen de portada no puede superar los 2 MB.', [['field' => 'file', 'issue' => 'tamaño mayor a 2 MB']], 422);
        }

        $mime = $file->getMimeType();
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return ApiResponse::error('VALIDATION_ERROR', 'El formato de imagen debe ser JPEG o PNG.', [['field' => 'file', 'issue' => 'formato inválido']], 422);
        }

        $ext = $mime === 'image/png' ? 'png' : 'jpg';
        $coverKey = (string) Str::uuid() . '.' . $ext;

        $dir = env('COVERS_DIR', storage_path('app/covers'));
        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0777, true, true);
        }

        $file->move($dir, $coverKey);

        $book->cover_key = $coverKey;
        $book->save();

        return response()->json($this->formatBook($book), 200);
    }

    public function getCover(string $id): Response|JsonResponse
    {
        $book = Book::whereNull('deleted_at')->find($id);
        if (!$book || !$book->cover_key) {
            return ApiResponse::error('NOT_FOUND', 'El libro no tiene portada.', [], 404);
        }

        $dir = env('COVERS_DIR', storage_path('app/covers'));
        $filePath = $dir . DIRECTORY_SEPARATOR . $book->cover_key;

        if (!File::exists($filePath)) {
            return ApiResponse::error('NOT_FOUND', 'El archivo de portada no fue encontrado en el servidor.', [], 404);
        }

        $mime = str_ends_with($book->cover_key, '.png') ? 'image/png' : 'image/jpeg';
        return response(File::get($filePath), 200)->header('Content-Type', $mime);
    }

    public function deleteCover(string $id): Response|JsonResponse
    {
        $book = Book::whereNull('deleted_at')->find($id);
        if (!$book) {
            return ApiResponse::error('NOT_FOUND', 'El libro no existe o fue dado de baja.', [], 404);
        }

        if ($book->cover_key) {
            $oldKey = $book->cover_key;
            $book->cover_key = null;
            $book->save();

            $dir = env('COVERS_DIR', storage_path('app/covers'));
            $filePath = $dir . DIRECTORY_SEPARATOR . $oldKey;
            if (File::exists($filePath)) {
                File::delete($filePath);
            }
        }

        return response()->noContent();
    }
}

