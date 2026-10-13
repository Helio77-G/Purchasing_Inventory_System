<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RequisitionService
{
    /**
     * Create a new Store Requisition (SR) or Purchase Requisition (PR) inside a transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function createRequisition(User $actor, array $data): Requisition
    {
        return DB::transaction(function () use ($actor, $data) {
            $type = strtoupper($data['type']);

            // Enforce department scope for department_staff and hod
            $departmentId = (int) $data['department_id'];
            if ($actor->hasRole([Role::SLUG_DEPARTMENT_STAFF, Role::SLUG_HOD])) {
                if ((int) $actor->department_id !== $departmentId) {
                    throw new AccessDeniedHttpException('You can only create requisitions for your assigned department.');
                }
            }

            $department = Department::where('id', $departmentId)->where('is_active', true)->first();
            if (! $department) {
                throw ValidationException::withMessages([
                    'department_id' => ['Selected department does not exist or is inactive.'],
                ]);
            }

            $storeId = ! empty($data['store_id']) ? (int) $data['store_id'] : null;
            if ($type === Requisition::TYPE_SR && ! $storeId) {
                throw ValidationException::withMessages([
                    'store_id' => ['A source store is required for Store Requisitions (SR).'],
                ]);
            }

            if ($storeId !== null) {
                $store = Store::where('id', $storeId)->where('is_active', true)->first();
                if (! $store) {
                    throw ValidationException::withMessages([
                        'store_id' => ['Selected store does not exist or is inactive.'],
                    ]);
                }
            }

            $validatedLines = $this->validateAndPrepareLineItems($data['items'] ?? []);

            $requisition = Requisition::create([
                'requisition_number' => $this->generateRequisitionNumber($type),
                'type' => $type,
                'department_id' => $department->id,
                'store_id' => $storeId,
                'requester_id' => $actor->id,
                'status' => Requisition::STATUS_PENDING,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($validatedLines as $line) {
                RequisitionItem::create([
                    'requisition_id' => $requisition->id,
                    'item_id' => $line['item']->id,
                    'requested_quantity' => $line['requested_quantity'],
                    'issued_quantity' => 0,
                    'uom' => $line['item']->uom,
                    'notes' => $line['notes'],
                ]);
            }

            return $requisition->load([
                'department',
                'store',
                'requester.role',
                'hodApprover.role',
                'gmApprover.role',
                'rejector.role',
                'items.item',
            ]);
        });
    }

    /**
     * Update an existing Pending requisition and replace its line items atomically.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateRequisition(User $actor, Requisition $requisition, array $data): Requisition
    {
        return DB::transaction(function () use ($actor, $requisition, $data) {
            /** @var Requisition $locked */
            $locked = Requisition::where('id', $requisition->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Requisition::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => ["Only Pending requisitions can be edited. Current status is [{$locked->status}]."],
                ]);
            }

            // Authorization check: requester, same-department HOD, or admin
            $isOwner = (int) $locked->requester_id === (int) $actor->id;
            $isSameDeptHod = $actor->hasRole(Role::SLUG_HOD) && (int) $actor->department_id === (int) $locked->department_id;
            $isAdmin = $actor->hasRole(Role::SLUG_ADMIN);

            if (! $isOwner && ! $isSameDeptHod && ! $isAdmin) {
                throw new AccessDeniedHttpException('You are not authorized to edit this requisition.');
            }

            $storeId = array_key_exists('store_id', $data)
                ? (! empty($data['store_id']) ? (int) $data['store_id'] : null)
                : $locked->store_id;

            if ($locked->type === Requisition::TYPE_SR && ! $storeId) {
                throw ValidationException::withMessages([
                    'store_id' => ['A source store is required for Store Requisitions (SR).'],
                ]);
            }

            if ($storeId !== null) {
                $store = Store::where('id', $storeId)->where('is_active', true)->first();
                if (! $store) {
                    throw ValidationException::withMessages([
                        'store_id' => ['Selected store does not exist or is inactive.'],
                    ]);
                }
            }

            $validatedLines = $this->validateAndPrepareLineItems($data['items'] ?? []);

            $locked->update([
                'store_id' => $storeId,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
            ]);

            $locked->items()->delete();

            foreach ($validatedLines as $line) {
                RequisitionItem::create([
                    'requisition_id' => $locked->id,
                    'item_id' => $line['item']->id,
                    'requested_quantity' => $line['requested_quantity'],
                    'issued_quantity' => 0,
                    'uom' => $line['item']->uom,
                    'notes' => $line['notes'],
                ]);
            }

            return $locked->fresh([
                'department',
                'store',
                'requester.role',
                'hodApprover.role',
                'gmApprover.role',
                'rejector.role',
                'items.item',
            ]);
        });
    }

    /**
     * HOD approval for a Pending SR or Pending PR within the HOD's department scope.
     * Note: Does NOT deduct stock or create stock ledger movements.
     */
    public function hodApprove(User $actor, Requisition $requisition): Requisition
    {
        return DB::transaction(function () use ($actor, $requisition) {
            /** @var Requisition $locked */
            $locked = Requisition::where('id', $requisition->id)->lockForUpdate()->firstOrFail();

            if (! $actor->hasRole(Role::SLUG_HOD)) {
                throw new AccessDeniedHttpException('Only a Head of Department (HOD) can perform HOD approval.');
            }

            if ((int) $actor->department_id !== (int) $locked->department_id) {
                throw new AccessDeniedHttpException('You can only approve requisitions within your assigned department.');
            }

            if ((int) $actor->id === (int) $locked->requester_id) {
                throw new AccessDeniedHttpException('Segregation of duties violation: You cannot approve your own requisition.');
            }

            if ($locked->status !== Requisition::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => ["Requisition cannot be HOD-approved from status [{$locked->status}]. Expected [Pending]."],
                ]);
            }

            if ($locked->items()->count() === 0) {
                throw ValidationException::withMessages([
                    'items' => ['Cannot approve a requisition with no line items.'],
                ]);
            }

            $locked->update([
                'status' => Requisition::STATUS_HOD_APPROVED,
                'hod_approved_by' => $actor->id,
                'hod_approved_at' => now(),
            ]);

            return $locked->fresh([
                'department',
                'store',
                'requester.role',
                'hodApprover.role',
                'gmApprover.role',
                'rejector.role',
                'items.item',
            ]);
        });
    }

    /**
     * GM approval for an HOD-Approved Purchase Requisition (PR).
     */
    public function gmApprove(User $actor, Requisition $requisition): Requisition
    {
        return DB::transaction(function () use ($actor, $requisition) {
            /** @var Requisition $locked */
            $locked = Requisition::where('id', $requisition->id)->lockForUpdate()->firstOrFail();

            if (! $actor->hasRole(Role::SLUG_GM)) {
                throw new AccessDeniedHttpException('Only the General Manager (GM) can perform GM approval.');
            }

            if ((int) $actor->id === (int) $locked->requester_id) {
                throw new AccessDeniedHttpException('Segregation of duties violation: You cannot approve your own requisition.');
            }

            if ($locked->type !== Requisition::TYPE_PR) {
                throw ValidationException::withMessages([
                    'type' => ['GM approval applies only to Purchase Requisitions (PR), not Store Requisitions (SR).'],
                ]);
            }

            if ($locked->status !== Requisition::STATUS_HOD_APPROVED) {
                throw ValidationException::withMessages([
                    'status' => ["Purchase Requisition cannot be GM-approved from status [{$locked->status}]. Expected [HOD Approved]."],
                ]);
            }

            $locked->update([
                'status' => Requisition::STATUS_GM_APPROVED,
                'gm_approved_by' => $actor->id,
                'gm_approved_at' => now(),
            ]);

            return $locked->fresh([
                'department',
                'store',
                'requester.role',
                'hodApprover.role',
                'gmApprover.role',
                'rejector.role',
                'items.item',
            ]);
        });
    }

    /**
     * Reject a Pending requisition (by same-department HOD) or HOD-Approved PR (by GM).
     */
    public function reject(User $actor, Requisition $requisition, string $reason): Requisition
    {
        return DB::transaction(function () use ($actor, $requisition, $reason) {
            /** @var Requisition $locked */
            $locked = Requisition::where('id', $requisition->id)->lockForUpdate()->firstOrFail();

            if ((int) $actor->id === (int) $locked->requester_id) {
                throw new AccessDeniedHttpException('Segregation of duties violation: You cannot review your own requisition.');
            }

            if ($locked->status === Requisition::STATUS_PENDING) {
                if (! $actor->hasRole(Role::SLUG_HOD) || (int) $actor->department_id !== (int) $locked->department_id) {
                    throw new AccessDeniedHttpException('Only the Head of Department (HOD) for this department can reject a Pending requisition.');
                }
            } elseif ($locked->status === Requisition::STATUS_HOD_APPROVED && $locked->type === Requisition::TYPE_PR) {
                if (! $actor->hasRole(Role::SLUG_GM)) {
                    throw new AccessDeniedHttpException('Only the General Manager (GM) can reject an HOD-Approved Purchase Requisition.');
                }
            } else {
                throw ValidationException::withMessages([
                    'status' => ["Requisition cannot be rejected from status [{$locked->status}]."],
                ]);
            }

            $locked->update([
                'status' => Requisition::STATUS_REJECTED,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => trim($reason),
            ]);

            return $locked->fresh([
                'department',
                'store',
                'requester.role',
                'hodApprover.role',
                'gmApprover.role',
                'rejector.role',
                'items.item',
            ]);
        });
    }

    /**
     * Validate and resolve line items against active Items in the database.
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     * @return array<int, array{item: Item, requested_quantity: float, notes: ?string}>
     */
    private function validateAndPrepareLineItems(array $rawItems): array
    {
        if (empty($rawItems)) {
            throw ValidationException::withMessages([
                'items' => ['At least one line item is required.'],
            ]);
        }

        $seenItemIds = [];
        $prepared = [];

        foreach ($rawItems as $index => $line) {
            $itemId = isset($line['item_id']) ? (int) $line['item_id'] : 0;
            $qty = isset($line['requested_quantity']) ? (float) $line['requested_quantity'] : 0.0;

            if ($itemId <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.item_id" => ['A valid item is required.'],
                ]);
            }

            if (in_array($itemId, $seenItemIds, true)) {
                throw ValidationException::withMessages([
                    "items.{$index}.item_id" => ['Duplicate items are not allowed in the same requisition.'],
                ]);
            }

            if ($qty <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.requested_quantity" => ['Requested quantity must be greater than zero.'],
                ]);
            }

            $item = Item::where('id', $itemId)->where('is_active', true)->first();
            if (! $item) {
                throw ValidationException::withMessages([
                    "items.{$index}.item_id" => ['Selected item does not exist or is inactive.'],
                ]);
            }

            $seenItemIds[] = $itemId;
            $prepared[] = [
                'item' => $item,
                'requested_quantity' => round($qty, 3),
                'notes' => isset($line['notes']) ? trim((string) $line['notes']) : null,
            ];
        }

        return $prepared;
    }

    /**
     * Generate a unique sequential requisition number (e.g., SR-202610-0001 or PR-202610-0001).
     */
    private function generateRequisitionNumber(string $type): string
    {
        $prefix = $type.'-'.now()->format('Ym').'-';

        $latest = Requisition::where('requisition_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('requisition_number');

        $nextSeq = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $matches)) {
            $nextSeq = ((int) $matches[1]) + 1;
        }

        return $prefix.str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
    }
}
