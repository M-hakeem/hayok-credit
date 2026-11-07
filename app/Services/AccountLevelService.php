<?php

namespace App\Services;

use App\Models\User;

class AccountLevelService
{
    public function recalculate(User $user): ?string
    {
        $hasNin = $user->nin_verified_at !== null;
        $hasBvn = $user->bvn_verified_at !== null;

        $level = match (true) {
            $hasNin && $hasBvn
                && $user->addresses()->whereNotNull('utility_bill_path')->exists()
                && $user->employments()->whereNotNull('bank_statement_path')->exists() => 'tier_3',
            $hasNin && $hasBvn => 'tier_2',
            $hasNin || $hasBvn => 'tier_1',
            default => null,
        };

        if ($user->account_level !== $level) {
            $user->forceFill(['account_level' => $level])->save();
        }

        return $level;
    }
}
