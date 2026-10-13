<?php

namespace Tests\Feature;

use App\Models\Disposition;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

/**
 * The DataTables search box on the lists. Yajra builds one big OR across every
 * searchable column, so a single column it cannot resolve (a snake_case data
 * key for a camelCase relation) breaks the whole query and the table shows
 * nothing for any search term. These requests mirror the exact column config
 * the views send.
 */
class LeadSearchTest extends TestCase
{
    use DatabaseTransactions;
    use UsesProjectDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first() ?? User::first();
    }

    /** @param list<string> $keys */
    private function dataTablesQuery(array $keys, string $term, array $unsearchable = [], ?int $orderColumn = null, string $dir = 'asc'): array
    {
        $columns = [];

        foreach ($keys as $i => $key) {
            $columns[] = [
                'data'       => $key,
                'name'       => $key === 'origin' ? 'channel' : $key,
                'searchable' => in_array($i, $unsearchable, true) ? 'false' : 'true',
                'orderable'  => 'true',
                'search'     => ['value' => '', 'regex' => 'false'],
            ];
        }

        return [
            'draw'    => 1,
            'start'   => 0,
            'length'  => 25,
            'columns' => $columns,
            'order'   => [['column' => $orderColumn ?? (count($keys) - 2), 'dir' => $dir]],
            'search'  => ['value' => $term, 'regex' => 'false'],
        ];
    }

    private const LEAD_COLUMNS = [
        '', 'name', 'mobile', 'origin', 'stage_badge', 'disposition_badge',
        'assigned_to.name', 'center.code', 'score_band', 'next_follow_up_at', 'created_at', 'action',
    ];

    public function test_the_leads_list_search_box_finds_a_lead_by_name(): void
    {
        $lead = Lead::create(['name' => 'Searchable Zebra Kulkarni', 'mobile' => '9123400001', 'status' => 'new']);

        $response = $this->actingAs($this->admin)
            ->getJson(route('leads.index', $this->dataTablesQuery(self::LEAD_COLUMNS, 'Zebra Kulkarni', [0, 11])))
            ->assertOk();

        $this->assertNull($response->json('error'), 'Yajra reports SQL failures inside the JSON, not as a 500.');
        $this->assertSame(1, $response->json('recordsFiltered'));
        $this->assertSame($lead->id, $response->json('data.0.id'));
    }

    public function test_the_leads_list_search_box_finds_a_lead_by_a_formatted_number_or_owner(): void
    {
        $agent = User::factory()->create(['name' => 'Owner Ptolemy Rao', 'is_active' => true]);
        $lead  = Lead::create(['name' => 'Number Search', 'mobile' => '9123400002', 'status' => 'new', 'assigned_to' => $agent->id]);

        $byNumber = $this->actingAs($this->admin)
            ->getJson(route('leads.index', $this->dataTablesQuery(self::LEAD_COLUMNS, '+91 91234-00002', [0, 11])))
            ->assertOk();

        $this->assertNull($byNumber->json('error'));
        $this->assertSame($lead->id, $byNumber->json('data.0.id'), 'Spaces, dashes and +91 in the search term are ignored.');

        $byOwner = $this->actingAs($this->admin)
            ->getJson(route('leads.index', $this->dataTablesQuery(self::LEAD_COLUMNS, 'Ptolemy', [0, 11])))
            ->assertOk();

        $this->assertNull($byOwner->json('error'));
        $this->assertSame($lead->id, $byOwner->json('data.0.id'), 'The Assigned To column is searchable through its relation.');

        // Sorting by the Assigned To header must not error either.
        $sorted = $this->actingAs($this->admin)
            ->getJson(route('leads.index', $this->dataTablesQuery(self::LEAD_COLUMNS, 'Number Search', [0, 11], 6, 'desc')))
            ->assertOk();

        $this->assertNull($sorted->json('error'));
        $this->assertSame($lead->id, $sorted->json('data.0.id'));
    }

    public function test_the_dispositions_settings_search_box_works(): void
    {
        $columns = ['name', 'slug', 'type', 'parent.name', 'moves_to_stage.name', 'requires_followup', 'is_active', 'sort_order', 'action'];

        $disposition = Disposition::whereNull('parent_id')->first();

        $response = $this->actingAs($this->admin)
            ->getJson(route('settings.dispositions.index', $this->dataTablesQuery($columns, $disposition->name, [8], 0)))
            ->assertOk();

        $this->assertNull($response->json('error'));
        $this->assertGreaterThanOrEqual(1, $response->json('recordsFiltered'));
    }
}
