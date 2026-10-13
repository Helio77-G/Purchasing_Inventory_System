<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\Department;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Part3RequisitionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_department_user_can_submit_valid_sr_and_invalid_payloads_are_rejected(): void
    {
        $hkStaff = User::where('email', 'staff.hk@hotel.local')->firstOrFail();
        $hkDept = Department::where('code', 'DEPT-HK')->firstOrFail();
        $kitDept = Department::where('code', 'DEPT-KIT')->firstOrFail();
        $mainStore = Store::where('code', 'STR-MAIN')->firstOrFail();
        $towel = Item::where('sku', 'ITM-HK-001')->firstOrFail();
        $shampoo = Item::where('sku', 'ITM-HK-002')->firstOrFail();

        Sanctum::actingAs($hkStaff);

        // 1. Empty items array rejected
        $this->postJson('/api/requisitions', [
            'type' => 'SR',
            'store_id' => $mainStore->id,
            'items' => [],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        // 2. Zero or negative quantity rejected
        $this->postJson('/api/requisitions', [
            'type' => 'SR',
            'store_id' => $mainStore->id,
            'items' => [
                ['item_id' => $towel->id, 'requested_quantity' => 0],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.requested_quantity']);

        $this->postJson('/api/requisitions', [
            'type' => 'SR',
            'store_id' => $mainStore->id,
            'items' => [
                ['item_id' => $towel->id, 'requested_quantity' => -5],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.requested_quantity']);

        // 3. SR without store_id rejected
        $this->postJson('/api/requisitions', [
            'type' => 'SR',
            'items' => [
                ['item_id' => $towel->id, 'requested_quantity' => 10],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['store_id']);

        // 4. Department user attempting to submit for another department rejected with 403
        $this->postJson('/api/requisitions', [
            'type' => 'SR',
            'department_id' => $kitDept->id,
            'store_id' => $mainStore->id,
            'items' => [
                ['item_id' => $towel->id, 'requested_quantity' => 10],
            ],
        ])->assertStatus(403);

        // 5. Valid SR creation ignores forged status or requester_id
        $res = $this->postJson('/api/requisitions', [
            'type' => 'SR',
            'store_id' => $mainStore->id,
            'status' => 'HOD Approved', // Forged status must be ignored
            'requester_id' => 999, // Forged requester_id must be ignored
            'notes' => 'Weekly housekeeping room linen & shampoo request',
            'items' => [
                ['item_id' => $towel->id, 'requested_quantity' => 25.5, 'notes' => 'Floor 3'],
                ['item_id' => $shampoo->id, 'requested_quantity' => 100],
            ],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.type', 'SR')
            ->assertJsonPath('data.status', 'Pending')
            ->assertJsonPath('data.department_id', $hkDept->id)
            ->assertJsonPath('data.requester_id', $hkStaff->id)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_sr_hod_approval_respects_department_scope_and_does_not_deduct_stock(): void
    {
        $hkStaff = User::where('email', 'staff.hk@hotel.local')->firstOrFail();
        $hkHod = User::where('email', 'hod.hk@hotel.local')->firstOrFail();
        $kitHod = User::where('email', 'hod.kit@hotel.local')->firstOrFail();
        $mainStore = Store::where('code', 'STR-MAIN')->firstOrFail();
        $towel = Item::where('sku', 'ITM-HK-001')->firstOrFail();

        // Seed an initial stock balance to confirm SR approval does NOT deduct it
        CurrentStock::create([
            'item_id' => $towel->id,
            'store_id' => $mainStore->id,
            'quantity' => 80.000,
        ]);

        Sanctum::actingAs($hkStaff);
        $srRes = $this->postJson('/api/requisitions', [
            'type' => 'SR',
            'store_id' => $mainStore->id,
            'items' => [
                ['item_id' => $towel->id, 'requested_quantity' => 20],
            ],
        ])->assertStatus(201);

        $srId = $srRes->json('data.id');

        // 1. HOD from another department (Kitchen HOD) cannot view or approve Housekeeping SR
        Sanctum::actingAs($kitHod);
        $this->getJson("/api/requisitions/{$srId}")->assertStatus(403);
        $this->postJson("/api/requisitions/{$srId}/hod-approve")->assertStatus(403);

        // 2. Department staff cannot approve SR
        Sanctum::actingAs($hkStaff);
        $this->postJson("/api/requisitions/{$srId}/hod-approve")->assertStatus(403);

        // 3. Authorized Housekeeping HOD approves SR (forged approver ID in body is ignored)
        Sanctum::actingAs($hkHod);
        $approveRes = $this->postJson("/api/requisitions/{$srId}/hod-approve", [
            'hod_approved_by' => $kitHod->id,
        ]);

        $approveRes->assertOk()
            ->assertJsonPath('data.status', Requisition::STATUS_HOD_APPROVED)
            ->assertJsonPath('data.hod_approved_by', $hkHod->id);
        $this->assertNotNull($approveRes->json('data.hod_approved_at'));

        // 4. Verify double HOD approval is rejected with 422
        $this->postJson("/api/requisitions/{$srId}/hod-approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // 5. Verify editing after HOD approval is rejected with 422
        $this->putJson("/api/requisitions/{$srId}", [
            'store_id' => $mainStore->id,
            'items' => [
                ['item_id' => $towel->id, 'requested_quantity' => 50],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // 6. Confirm CurrentStock is still 80.000 and StockLedger has 0 entries
        $this->assertEquals(
            '80.000',
            CurrentStock::where('item_id', $towel->id)->where('store_id', $mainStore->id)->value('quantity')
        );
        $this->assertEquals(0, StockLedger::count());
    }

    public function test_pr_workflow_follows_pending_to_hod_approved_to_gm_approved_and_blocks_stage_skipping(): void
    {
        $kitStaff = User::where('email', 'staff.kit@hotel.local')->firstOrFail();
        $kitHod = User::where('email', 'hod.kit@hotel.local')->firstOrFail();
        $gm = User::where('email', 'gm@hotel.local')->firstOrFail();
        $rice = Item::where('sku', 'ITM-KIT-001')->firstOrFail();
        $oliveOil = Item::where('sku', 'ITM-KIT-002')->firstOrFail();

        // 1. Kitchen Staff creates PR
        Sanctum::actingAs($kitStaff);
        $prRes = $this->postJson('/api/requisitions', [
            'type' => 'PR',
            'notes' => 'Monthly dry grocery purchase request',
            'items' => [
                ['item_id' => $rice->id, 'requested_quantity' => 30],
            ],
        ])->assertStatus(201)
            ->assertJsonPath('data.type', 'PR')
            ->assertJsonPath('data.status', Requisition::STATUS_PENDING);

        $prId = $prRes->json('data.id');

        // 2. Kitchen Staff updates Pending PR before approval
        $this->putJson("/api/requisitions/{$prId}", [
            'notes' => 'Updated monthly dry grocery purchase request',
            'items' => [
                ['item_id' => $rice->id, 'requested_quantity' => 35],
                ['item_id' => $oliveOil->id, 'requested_quantity' => 10],
            ],
        ])->assertOk()
            ->assertJsonCount(2, 'data.items');

        // 3. GM cannot skip HOD approval stage on a Pending PR
        Sanctum::actingAs($gm);
        $this->postJson("/api/requisitions/{$prId}/gm-approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // 4. Kitchen HOD approves PR -> status becomes HOD Approved
        Sanctum::actingAs($kitHod);
        $this->postJson("/api/requisitions/{$prId}/hod-approve")
            ->assertOk()
            ->assertJsonPath('data.status', Requisition::STATUS_HOD_APPROVED)
            ->assertJsonPath('data.hod_approved_by', $kitHod->id);

        // 5. HOD cannot perform GM approval
        $this->postJson("/api/requisitions/{$prId}/gm-approve")
            ->assertStatus(403);

        // 6. GM approves HOD-Approved PR -> status becomes GM Approved
        Sanctum::actingAs($gm);
        $gmRes = $this->postJson("/api/requisitions/{$prId}/gm-approve")
            ->assertOk()
            ->assertJsonPath('data.status', Requisition::STATUS_GM_APPROVED)
            ->assertJsonPath('data.gm_approved_by', $gm->id);
        $this->assertNotNull($gmRes->json('data.gm_approved_at'));

        // 7. Duplicate GM approval is rejected with 422
        $this->postJson("/api/requisitions/{$prId}/gm-approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // 8. No stock changes or PO records were automatically created by PR approval
        $this->assertEquals(0, StockLedger::count());
        $this->assertEquals(0, CurrentStock::count());
    }

    public function test_segregation_of_duties_prevents_users_from_approving_their_own_requisitions(): void
    {
        $hkHod = User::where('email', 'hod.hk@hotel.local')->firstOrFail();
        $mainStore = Store::where('code', 'STR-MAIN')->firstOrFail();
        $towel = Item::where('sku', 'ITM-HK-001')->firstOrFail();

        // Housekeeping HOD creates an SR themselves
        Sanctum::actingAs($hkHod);
        $srRes = $this->postJson('/api/requisitions', [
            'type' => 'SR',
            'store_id' => $mainStore->id,
            'items' => [
                ['item_id' => $towel->id, 'requested_quantity' => 15],
            ],
        ])->assertStatus(201);

        $srId = $srRes->json('data.id');

        // Same HOD attempting to approve their own SR is blocked by Segregation of Duties (403)
        $this->postJson("/api/requisitions/{$srId}/hod-approve")
            ->assertStatus(403);
    }

    public function test_rejection_workflow_records_actor_timestamp_and_reason_and_locks_further_actions(): void
    {
        $hkStaff = User::where('email', 'staff.hk@hotel.local')->firstOrFail();
        $hkHod = User::where('email', 'hod.hk@hotel.local')->firstOrFail();
        $gm = User::where('email', 'gm@hotel.local')->firstOrFail();
        $towel = Item::where('sku', 'ITM-HK-001')->firstOrFail();

        Sanctum::actingAs($hkStaff);
        $prRes = $this->postJson('/api/requisitions', [
            'type' => 'PR',
            'items' => [
                ['item_id' => $towel->id, 'requested_quantity' => 500],
            ],
        ])->assertStatus(201);

        $prId = $prRes->json('data.id');

        // 1. Rejection requires a reason
        Sanctum::actingAs($hkHod);
        $this->postJson("/api/requisitions/{$prId}/reject", [
            'rejection_reason' => '',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['rejection_reason']);

        // 2. HOD approves first, then GM rejects at GM stage
        $this->postJson("/api/requisitions/{$prId}/hod-approve")->assertOk();

        Sanctum::actingAs($gm);
        $rejectRes = $this->postJson("/api/requisitions/{$prId}/reject", [
            'rejection_reason' => 'Exceeds Q4 housekeeping capex budget; reduce quantity to 200.',
        ]);

        $rejectRes->assertOk()
            ->assertJsonPath('data.status', Requisition::STATUS_REJECTED)
            ->assertJsonPath('data.rejected_by', $gm->id)
            ->assertJsonPath('data.rejection_reason', 'Exceeds Q4 housekeeping capex budget; reduce quantity to 200.');
        $this->assertNotNull($rejectRes->json('data.rejected_at'));

        // 3. Cannot approve or reject again once Rejected
        $this->postJson("/api/requisitions/{$prId}/gm-approve")->assertStatus(422);
        $this->postJson("/api/requisitions/{$prId}/reject", [
            'rejection_reason' => 'Second rejection attempt',
        ])->assertStatus(422);
    }
}
