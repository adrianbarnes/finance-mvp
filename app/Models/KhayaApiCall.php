<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\KhayaApiCallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request sent to the Khaya API, used to enforce the plan quota.
 *
 * @property int $id
 * @property int|null $call_session_id
 * @property string $endpoint
 * @property string $language
 * @property int|null $http_status
 * @property string|null $error_code
 * @property int|null $latency_ms
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['call_session_id', 'endpoint', 'language', 'http_status', 'error_code', 'latency_ms'])]
class KhayaApiCall extends Model
{
    /** @use HasFactory<KhayaApiCallFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<CallSession, $this>
     */
    public function callSession(): BelongsTo
    {
        return $this->belongsTo(CallSession::class);
    }
}
