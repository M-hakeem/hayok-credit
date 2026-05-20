<?php

namespace Tests\Unit;

use App\Models\LoanInterestSetting;
use Tests\TestCase;

class LoanInterestSettingTest extends TestCase
{
    public function test_rate_for_specific_tenure_is_selected()
    {
        LoanInterestSetting::create([
            'interest_rate' => 10.0,
            'tenure_months' => 3,
            'active' => true,
        ]);

        LoanInterestSetting::create([
            'interest_rate' => 12.0,
            'tenure_months' => 6,
            'active' => true,
        ]);

        $this->assertSame(10.0, LoanInterestSetting::rateForTenure(3));
        $this->assertSame(12.0, LoanInterestSetting::rateForTenure(6));
    }

    public function test_rate_falls_back_to_default_when_tenure_is_missing()
    {
        LoanInterestSetting::create([
            'interest_rate' => 11.0,
            'tenure_months' => null,
            'active' => true,
        ]);

        $this->assertSame(11.0, LoanInterestSetting::rateForTenure(9));
    }
}
