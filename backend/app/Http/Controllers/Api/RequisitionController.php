<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CurrentStock;
use App\Models\Requisition;
use App\Models\Role;
use App\Services\RequisitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RequisitionController extends Controller
{
    private const ALLOWED_SORT_FIELDS = [
        'id',
        'requisition_number',
        'type',
        'status',
        'created_at',
    ];

    public function __construct(
        private readonly RequisitionService $requisitionService
    ) {}

    /**
     * List Store Requisitions (SR) and Purchase Requisitions (PR) with department scoping.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', Rule::in(Requisition::ALLOWED_TYPES)],
            'status' => ['nullable', 'string', 'max:32'],
            'department_id' => ['nullable', 'integer'],
            'store_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:150'],
            'sort_by' => ['nullable', 'string', Rule::in(self::ALLOWED_SORT_FIELDS)],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc', 'ASC', 'DESC'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $user->loadMissing('role');

        $query = Requisition::with([
            'department',
            'store',
            'requester.role',
            'hodApprover.role',
            'gmApprover.role',
            'rejector.role',
            'items.item',
        ]);

        // Department-scoped visibility for department_staff and hod
        if ($user->hasRole([Role::SLUG_DEPARTMENT_STAFF, Role::SLUG_HOD])) {
            $query->where('department_id', $user->department_id);
        } elseif (! empty($validated['department_id'])) {
            $query->where('department_id', (int) $validated['department_id']);
        }

        if (! empty($validated['type'])) {
            $query->where('type', strtoupper($validated['type']));
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['store_id'])) {
            $query->where('store_id', (int) $validated['store_id']);
        }

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($q) use ($search) {
                $q->where('requisition_number', 'like', "%{$search}%")
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
     * Create a new SR or PR.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        if ($request->has('type') && is_string($request->input('type'))) {
            $request->merge(['type' => strtoupper(trim($request->input('type')))]);
        }

        if (! $request->filled('department_id') && $user->department_id) {
            $request->merge(['department_id' => $user->department_id]);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(Requisition::ALLOWED_TYPES)],
            'department_id' => ['required', 'integer'],
            'store_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'distinct'],
            'items.*.requested_quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $requisition = $this->requisitionService->createRequisition($user, $validated);

        return response()->json([
            'message' => "{$requisition->type} created successfully.",
            'data' => $requisition,
        ], 201);
    }

    /**
     * Display a single SR or PR with line items and approval history.
     */
    public function show(Request $request, Requisition $requisition): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        if ($user->hasRole([Role::SLUG_DEPARTMENT_STAFF, Role::SLUG_HOD])) {
            if ((int) $user->department_id !== (int) $requisition->department_id) {
                return response()->json([
                    'message' => 'You are not authorized to view requisitions from another department.',
                ], 403);
            }
        }

        $requisition->load([
            'department',
            'store',
            'requester.role',
            'hodApprover.role',
            'gmApprover.role',
            'rejector.role',
            'items.item',
            'purchaseOrders',
            'stockIssues.issuer',
        ]);

        if ($requisition->store_id) {
            $stockMap = CurrentStock::where('store_id', $requisition->store_id)
                ->whereIn('item_id', $requisition->items->pluck('item_id'))
                ->pluck('quantity', 'item_id');

            foreach ($requisition->items as $reqItem) {
                $reqItem->setAttribute('available_stock', number_format((float) ($stockMap[$reqItem->item_id] ?? 0), 3, '.', ''));
            }
        }

        return response()->json([
            'data' => $requisition,
        ]);
    }

    /**
     * Update an eligible Pending requisition.
     */
    public function update(Request $request, Requisition $requisition): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        $validated = $request->validate([
            'store_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'distinct'],
            'items.*.requested_quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $updated = $this->requisitionService->updateRequisition($user, $requisition, $validated);

        return response()->json([
            'message' => 'Requisition updated successfully.',
            'data' => $updated,
        ]);
    }

    /**
     * HOD approval stage for a Pending SR or Pending PR.
     */
    public function hodApprove(Request $request, Requisition $requisition): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        $approved = $this->requisitionService->hodApprove($user, $requisition);

        return response()->json([
            'message' => 'Requisition approved by HOD.',
            'data' => $approved,
        ]);
    }

    /**
     * GM approval stage for an HOD-Approved PR.
     */
    public function gmApprove(Request $request, Requisition $requisition): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        $approved = $this->requisitionService->gmApprove($user, $requisition);

        return response()->json([
            'message' => 'Purchase Requisition approved by GM.',
            'data' => $approved,
        ]);
    }

    /**
     * Reject a requisition at the current eligible approval stage.
     */
    public function reject(Request $request, Requisition $requisition): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $rejected = $this->requisitionService->reject($user, $requisition, $validated['rejection_reason']);

        return response()->json([
            'message' => 'Requisition rejected.',
            'data' => $rejected,
        ]);
    }
}
