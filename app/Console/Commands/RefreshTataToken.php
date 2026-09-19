<?php

namespace App\Console\Commands;

use App\Services\Telephony\SmartfloClient;
use App\Services\Telephony\TataException;
use Illuminate\Console\Command;

/**
 * Keeps the Tata Smartflo access token alive.
 *
 * Smartflo's login token is short-lived (typically one hour), so a stored token
 * would otherwise stop working. This command re-mints it from the saved login
 * credentials before it lapses, so calls placed from the web panel never fail
 * on an expired token. It is scheduled every 30 minutes and also refreshes well
 * ahead of expiry (default 50 minutes), which comfortably covers a 1-hour token
 * and, for a longer portal token, refreshes it before it runs out.
 */
class RefreshTataToken extends Command
{
    protected $signature = 'tata:refresh-token {--force : Refresh even if the current token is still valid}';

    protected $description = 'Refresh the Tata Smartflo access token from saved credentials before it expires';

    public function handle(SmartfloClient $tata): int
    {
        if (! $tata->loginId() || ! $tata->password()) {
            $this->info('No Smartflo login credentials saved; nothing to refresh.');

            return self::SUCCESS;
        }

        $within = (int) config('telephony.tata.token_refresh_before', 3000);

        if (! $this->option('force') && ! $tata->tokenNeedsRefresh($within)) {
            $this->info('Token still valid until ' . ($tata->tokenExpiresAt()?->toDateTimeString() ?? 'unknown') . '; no refresh needed.');

            return self::SUCCESS;
        }

        try {
            $result = $tata->refreshAccessToken();
        } catch (TataException $e) {
            $this->error('Smartflo token refresh failed: ' . $e->displayMessage());

            return self::FAILURE;
        }

        $this->info('Smartflo token refreshed; valid until ' . ($tata->tokenExpiresAt()?->toDateTimeString() ?? 'unknown') . '.');

        return self::SUCCESS;
    }
}
