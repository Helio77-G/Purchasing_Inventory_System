<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemController extends Controller
{
    private const ALLOWED_SORT_FIELDS = [
        'id',
        'sku',
        'name',
        'category',
        'uom',
        'min_par_level',
        'is_active',
        'created_at',
    ];

    /**
     * Paginated list of items with search, category/active filter, and allow-listed sorting.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', 'string', Rule::in(self::ALLOWED_SORT_FIELDS)],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc', 'ASC', 'DESC'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Item::query();

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        if ($request->has('is_active') && $request->input('is_active') !== '' && $request->input('is_active') !== null) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sortBy = $validated['sort_by'] ?? 'sku';
        if (! in_array($sortBy, self::ALLOWED_SORT_FIELDS, true)) {
            $sortBy = 'sku';
        }
        $sortDir = strtolower($validated['sort_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $perPage = (int) ($validated['per_page'] ?? 15);
        $paginator = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return response()->json($paginator);
    }

    /**
     * Store a new item/product.
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->has('sku') && is_string($request->input('sku'))) {
            $request->merge(['sku' => strtoupper(trim($request->input('sku')))]);
        }
        if ($request->has('uom') && is_string($request->input('uom'))) {
            $request->merge(['uom' => strtoupper(trim($request->input('uom')))]);
        }

        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:64', 'unique:items,sku'],
            'name' => ['required', 'string', 'max:200'],
            'category' => ['required', 'string', 'max:100'],
            'uom' => ['required', 'string', Rule::in(Item::ALLOWED_UOMS)],
            'min_par_level' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $validated['is_active'] = $validated['is_active'] ?? true;

        $item = Item::create($validated);

        return response()->json([
            'message' => 'Item created successfully.',
            'data' => $item,
        ], 201);
    }

    /**
     * Display item details.
     */
    public function show(Item $item): JsonResponse
    {
        return response()->json([
            'data' => $item,
        ]);
    }

    /**
     * Update an existing item/product.
     */
    public function update(Request $request, Item $item): JsonResponse
    {
        if ($request->has('sku') && is_string($request->input('sku'))) {
            $request->merge(['sku' => strtoupper(trim($request->input('sku')))]);
        }
        if ($request->has('uom') && is_string($request->input('uom'))) {
            $request->merge(['uom' => strtoupper(trim($request->input('uom')))]);
        }

        $validated = $request->validate([
            'sku' => [
                'required',
                'string',
                'max:64',
                Rule::unique('items', 'sku')->ignore($item->id),
            ],
            'name' => ['required', 'string', 'max:200'],
            'category' => ['required', 'string', 'max:100'],
            'uom' => ['required', 'string', Rule::in(Item::ALLOWED_UOMS)],
            'min_par_level' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $item->update($validated);

        return response()->json([
            'message' => 'Item updated successfully.',
            'data' => $item->fresh(),
        ]);
    }

    /**
     * Deactivate an item to preserve historical transaction references.
     */
    public function deactivate(Item $item): JsonResponse
    {
        $item->update(['is_active' => false]);

        return response()->json([
            'message' => 'Item deactivated successfully.',
            'data' => $item->fresh(),
        ]);
    }

    /**
     * Delete an item only if it is not referenced by any purchasing or inventory records.
     */
    public function destroy(Item $item): JsonResponse
    {
        $isReferenced = $item->requisitionItems()->exists()
            || $item->poItems()->exists()
            || $item->grnItems()->exists()
            || $item->currentStocks()->exists()
            || $item->stockLedgerEntries()->exists();

        if ($isReferenced) {
            return response()->json([
                'message' => 'Cannot delete item because it is referenced by historical purchasing or inventory records. Please deactivate it instead.',
                'errors' => [
                    'item' => ['Item is referenced by historical records and cannot be deleted.'],
                ],
            ], 422);
        }

        $item->delete();

        return response()->json([
            'message' => 'Item deleted successfully.',
        ]);
    }
}
