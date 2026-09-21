<?php

namespace Tests\Feature\Nomina;

use App\Livewire\Nomina\Revisar;
use App\Models\Company;
use App\Models\PayPeriod;
use App\Models\RawMark;
use App\Models\UploadedFile;
use App\Models\User;
use Carbon\Carbon;
use App\Services\CurrentCompany;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\RefreshDatabaseSafely;
use Tests\TestCase;

class DeletedUploadRawMarksTest extends TestCase
{
    use RefreshDatabaseSafely;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionRoleSeeder::class);
    }

    public function test_review_excludes_marks_from_a_soft_deleted_upload_from_rows_and_counts(): void
    {
        $company = Company::factory()->create();
        $payPeriod = PayPeriod::factory()->forCompany($company)->create();
        $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
        $staleMark = RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
            'status' => 'unknown_employee',
            'event_at' => Carbon::parse('2026-01-05 08:00:00'),
        ]);
        $file->delete();
        $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
        $this->actingAs($admin);

        Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
            ->assertViewHas('records', fn ($records): bool => $records->isEmpty())
            ->assertViewHas('summary', fn (array $summary): bool => $summary['total'] === 0
                && $summary['unknown_employee'] === 0)
            ->assertDontSee($staleMark->employee_external_id);
    }

    public function test_stale_invalid_marks_from_a_soft_deleted_upload_do_not_block_readiness(): void
    {
        Queue::fake();
        $company = Company::factory()->create();
        $payPeriod = PayPeriod::factory()->forCompany($company)->create([
            'start_date' => '2026-01-05',
            'end_date' => '2026-01-11',
            'status' => 'validating',
        ]);
        $file = UploadedFile::factory()->forCompany($company)->forPayPeriod($payPeriod)->create();
        RawMark::factory()->forCompany($company)->forPayPeriod($payPeriod)->forUploadedFile($file)->create([
            'employee_external_id' => 'unknown-123',
            'employee_id' => null,
            'event_at' => Carbon::parse('2026-01-05 06:00:00'),
            'status' => 'unknown_employee',
        ]);
        $file->delete();
        $admin = User::factory()->forCompany($company)->create()->assignRole('company_admin');
        $this->actingAs($admin);
        app(CurrentCompany::class)->set($company);

        Livewire::test(Revisar::class, ['payPeriod' => $payPeriod])
            ->call('continueToReady')
            ->assertHasNoErrors()
            ->assertSet('showReadyConfirm', false)
            ->assertSet('readyMessage', null);

        $this->assertSame('ready', $payPeriod->fresh()->status);
    }
}
