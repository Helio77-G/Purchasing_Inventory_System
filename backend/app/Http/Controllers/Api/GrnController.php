<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceivingNote;
use App\Models\Role;
use App\Services\GrnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GrnController extends Controller
{
    private const ALLOWED_SORT_FIELDS = [
        'id',
        'grn_number',
        'status',
        'received_at',
        'approved_at',
        'created_at',
    ];

    public function __construct(
        private readonly GrnService $grnService
    ) {}

    /**
     * Paginated list of Goods Receiving Notes (GRNs) with filters and store scoping.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:32'],
            'store_id' => ['nullable', 'integer'],
            'purchase_order_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:150'],
            'sort_by' => ['nullable', 'string', Rule::in(self::ALLOWED_SORT_FIELDS)],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc', 'ASC', 'DESC'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $user->loadMissing('role');

        $query = GoodsReceivingNote::with([
            'purchaseOrder.supplier',
            'store',
            'receiver.role',
            'approver.role',
            'rejector.role',
            'items.item',
            'items.poItem',
        ]);

        if ($user->hasRole([Role::SLUG_STORE_KEEPER, Role::SLUG_STORE_MANAGER]) && $user->store_id !== null) {
            $query->where('store_id', $user->store_id);
        } elseif (! empty($validated['store_id'])) {
            $query->where('store_id', (int) $validated['store_id']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['purchase_order_id'])) {
            $query->where('purchase_order_id', (int) $validated['purchase_order_id']);
        }

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($q) use ($search) {
                $q->where('grn_number', 'like', "%{$search}%")
                    ->orWhere('delivery_note_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $sortBy = $validated['sort_by'] ?? 'id';
        if (! in_array($sortBy, self::ALLOWED_SORT_FIELDS, true)) {
            $sortBy = 'id';
        }
        $sortDir = strtolower($validated['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $perPage = (int) ($validated['per_page'] ?? 15);
        $paginator = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return response()->json($paginator);
    }

    /**
     * Create a Pending Goods Receiving Note against an eligible PO.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        if (! $request->filled('store_id') && $user->store_id !== null) {
            $request->merge(['store_id' => $user->store_id]);
        }

        $validated = $request->validate([
            'purchase_order_id' => ['required', 'integer'],
            'store_id' => ['required', 'integer'],
            'delivery_note_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.po_item_id' => ['nullable', 'integer'],
            'items.*.item_id' => ['nullable', 'integer'],
            'items.*.received_quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $grn = $this->grnService->createGrn($user, $validated);

        return response()->json([
            'message' => 'Goods Receiving Note recorded and awaiting Store Manager approval.',
            'data' => $grn,
        ], 201);
    }

    /**
     * Display GRN details with PO reference, receiving store, line items, and ledger entries.
     */
    public function show(Request $request, GoodsReceivingNote $grn): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        if ($user->hasRole([Role::SLUG_STORE_KEEPER, Role::SLUG_STORE_MANAGER]) && $user->store_id !== null) {
            if ((int) $user->store_id !== (int) $grn->store_id) {
                return response()->json([
                    'message' => 'You are not authorized to view GRNs from another store.',
                ], 403);
            }
        }

        $grn->load([
            'purchaseOrder.supplier',
            'purchaseOrder.items.item',
            'store',
            'receiver.role',
            'approver.role',
            'rejector.role',
            'items.item',
            'items.poItem',
            'stockLedgerEntries.actor',
        ]);

        return response()->json([
            'data' => $grn,
        ]);
    }

    /**
     * Approve/Post a Pending GRN and execute atomic Stock IN posting.
     * Restricted strictly to Store Managers.
     */
    public function approve(Request $request, GoodsReceivingNote $grn): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        $approved = $this->grnService->approveGrn($user, $grn);

        return response()->json([
            'message' => 'GRN approved and stock IN posted successfully.',
            'data' => $approved,
        ]);
    }

    /**
     * Reject a Pending GRN without posting stock.
     */
    public function reject(Request $request, GoodsReceivingNote $grn): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $rejected = $this->grnService->rejectGrn($user, $grn, $validated['rejection_reason']);

        return response()->json([
            'message' => 'Goods Receiving Note rejected.',
            'data' => $rejected,
        ]);
    }
}
