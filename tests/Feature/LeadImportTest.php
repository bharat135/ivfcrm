<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

class LeadImportTest extends TestCase
{
    use DatabaseTransactions, UsesProjectDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->admin = User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first();
        $this->assertNotNull($this->admin, 'Seed the database first: php artisan db:seed');
    }

    private function upload(UploadedFile $file)
    {
        return $this->actingAs($this->admin)->post(route('imports.store'), [
            'csv_file'     => $file,
            'on_duplicate' => 'skip',
        ]);
    }

    public function test_a_tab_separated_windows_1252_export_imports_instead_of_crashing(): void
    {
        // Excel's "Text" export: tabs, ANSI encoding, a non-breaking space (0xA0)
        // in a name, extra disposition columns and a re-uploaded error_reason column.
        $content = "date\tname\tmobile\tsource\tcity\tsub_disposition_\tremark\terror_reason\r\n"
                 . "01-09-2026\tManjali\xA0Sonkamble\t9359240518\tFree IVF\tshirur\tCALL BACK\tlatter date\tMobile number is required.\r\n"
                 . "02-09-2026\tunknown\t7218849665\t\t\tRINGING\t\t\r\n"
                 . "03-09-2026\tNo Phone\t\t\t\t\t\t\r\n";

        $this->upload(UploadedFile::fake()->createWithContent('Lead sheet.csv', $content))
            ->assertRedirect();

        $import = LeadImport::latest('id')->first();
        $this->assertSame('completed', $import->status);
        $this->assertSame(2, $import->imported_rows);
        $this->assertSame(1, $import->failed_rows);

        $lead = Lead::where('mobile', '9359240518')->first();
        $this->assertSame('Manjali Sonkamble', $lead->name);
        $this->assertSame('2026-09-01', $lead->lead_date->toDateString());
        $this->assertStringContainsString('latter date', $lead->remark);
        $this->assertStringContainsString('sub_disposition_: CALL BACK', $lead->remark);
        $this->assertStringContainsString('Source: Free IVF', $lead->remark);
        $this->assertStringNotContainsString('error_reason', $lead->remark);
    }

    public function test_a_utf8_comma_csv_with_bom_and_header_aliases_imports(): void
    {
        $content = "\xEF\xBB\xBFFull Name,Mobile No,Date\nPriya Sharma,+91 98765 01299,2026-09-05\n";

        $this->upload(UploadedFile::fake()->createWithContent('leads.csv', $content))->assertRedirect();

        $lead = Lead::where('dedup_hash', \App\Support\PhoneNumber::dedupHash('9876501299'))->first();
        $this->assertNotNull($lead);
        $this->assertSame('Priya Sharma', $lead->name);
        $this->assertSame('2026-09-05', $lead->lead_date->toDateString());
    }

    public function test_an_excel_workbook_imports(): void
    {
        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([
            ['Name', 'Mobile', 'Remark'],
            ['Excel Lead', 9876501288, 'from xlsx'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($book))->save($path);

        $this->upload(new UploadedFile($path, 'leads.xlsx', null, null, true))->assertRedirect();

        $lead = Lead::where('mobile', '9876501288')->first();
        $this->assertNotNull($lead);
        $this->assertSame('Excel Lead', $lead->name);
        $this->assertSame('from xlsx', $lead->remark);
    }

    public function test_a_number_already_in_the_crm_counts_as_a_duplicate(): void
    {
        Lead::create(['name' => 'Existing', 'mobile' => '9876501277', 'status' => 'new']);

        $this->upload(UploadedFile::fake()->createWithContent('d.csv', "name,mobile\nAgain,+919876501277\n"));

        $import = LeadImport::latest('id')->first();
        $this->assertSame(1, $import->duplicate_rows);
        $this->assertSame(0, $import->imported_rows);
    }

    public function test_a_file_without_a_mobile_column_is_refused_with_a_message(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('x.csv', "foo,bar\n1,2\n"))
            ->assertRedirect(route('imports.create'))
            ->assertSessionHas('error');

        $this->assertSame('failed', LeadImport::latest('id')->first()->status);
    }
}
