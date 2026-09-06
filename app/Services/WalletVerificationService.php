<?php

namespace App\Services;

/**
 * Backwards-compatible name for the consolidated Claimify wallet client.
 */
class WalletVerificationService extends ClaimifyWalletService
{
	protected function baseUrl(): string
	{
		return (string) config('services.wallet_verification.base_url');
	}

	protected function token(): ?string
	{
		return config('services.wallet_verification.token');
	}
}
