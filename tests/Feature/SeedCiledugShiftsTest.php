<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Division;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedCiledugShiftsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_three_brands_get_their_shifts_with_split_hours_and_talenta_codes(): void
    {
        $company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);

        $this->artisan('shifts:seed-ciledug', ['company' => 'PFC'])->assertSuccessful();

        $this->assertSame(['JCHICKEN', 'LUUCA', 'MOMOYO'], Division::where('company_id', $company->id)->orderBy('name')->pluck('name')->all());

        $split = Shift::where('name', 'Split')->firstOrFail();
        $this->assertSame('MOMOYO · Split (07:45 - 13:00 & 17:45 - 22:00)', $split->load('division')->label());
        $this->assertSame('Regular 11', $split->talenta_code);

        $splitGc = Shift::where('name', 'Split GC')->firstOrFail();
        $this->assertSame('07:45 - 13:00 & 20:00 - 23:00', $splitGc->hoursText());

        $saturday = Shift::where('name', 'Siang 14 (Sabtu)')->firstOrFail();
        $this->assertSame(['14:30', '23:30', 'LUUCA SHIFT 14.30'], [substr($saturday->start_time, 0, 5), substr($saturday->end_time, 0, 5), $saturday->talenta_code]);

        $this->assertSame(0, Shift::whereNull('division_id')->count());
        $this->assertSame(540, Shift::with('division')->where('name', 'Pagi')->where('code', 'P')->get()->first(fn (Shift $shift) => $shift->division?->name === 'JCHICKEN')->scheduledMinutes());
    }

    public function test_running_it_again_creates_nothing_and_keeps_edits(): void
    {
        $company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->artisan('shifts:seed-ciledug', ['company' => (string) $company->id])->assertSuccessful();
        $count = Shift::count();
        Shift::where('name', 'Split')->update(['late_tolerance_minutes' => 5, 'is_active' => false]);

        $this->artisan('shifts:seed-ciledug', ['company' => 'PFC'])->assertSuccessful();

        $this->assertSame($count, Shift::count());
        $this->assertFalse(Shift::where('name', 'Split')->firstOrFail()->is_active);
    }

    public function test_dry_run_and_unknown_company_change_nothing(): void
    {
        Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);

        $this->artisan('shifts:seed-ciledug', ['company' => 'PFC', '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, Shift::count());
        $this->assertSame(0, Division::count());

        $this->artisan('shifts:seed-ciledug', ['company' => 'NOPE'])->assertFailed();
    }
}
