<?php

namespace App\Services\Khaya;

use App\Models\KhayaApiCall;
use App\Services\Speech\SpeechQuotaExceeded;

/**
 * Keeps Khaya usage inside the plan limits (free developer tier:
 * 100 calls a month, 10 a minute) using the logged API calls.
 */
class KhayaQuotaGuard
{
    /**
     * @throws SpeechQuotaExceeded
     */
    public function ensureAvailable(): void
    {
        if ($this->usedThisMonth() >= $this->monthlyQuota()) {
            throw new SpeechQuotaExceeded(SpeechQuotaExceeded::Monthly);
        }

        if ($this->usedInLastMinute() >= $this->perMinuteLimit()) {
            throw new SpeechQuotaExceeded(SpeechQuotaExceeded::PerMinute);
        }
    }

    public function usedThisMonth(): int
    {
        return KhayaApiCall::query()->where('created_at', '>=', now()->startOfMonth())->count();
    }

    public function usedInLastMinute(): int
    {
        return KhayaApiCall::query()->where('created_at', '>', now()->subMinute())->count();
    }

    public function remainingThisMonth(): int
    {
        return max(0, $this->monthlyQuota() - $this->usedThisMonth());
    }

    public function monthlyQuota(): int
    {
        return (int) config('services.khaya.monthly_quota');
    }

    public function perMinuteLimit(): int
    {
        return (int) config('services.khaya.per_minute_limit');
    }

    /**
     * @return array{driver: string, monthly_quota: int, used_this_month: int, remaining_this_month: int, per_minute_limit: int}
     */
    public function summary(): array
    {
        return [
            'driver' => (string) config('services.khaya.driver'),
            'monthly_quota' => $this->monthlyQuota(),
            'used_this_month' => $this->usedThisMonth(),
            'remaining_this_month' => $this->remainingThisMonth(),
            'per_minute_limit' => $this->perMinuteLimit(),
        ];
    }
}
