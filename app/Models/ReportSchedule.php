<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * A template run on a schedule for its owner, delivered to explicit users.
 * Runs with `run_token`: the owner's pizzasys token for this schedule only,
 * minted with the owner's own bearer, encrypted at rest and revoked before
 * the schedule is deleted. The token lapses at `token_expires_at`.
 */
class ReportSchedule extends Model
{
    protected $fillable = [
        'template_id',
        'owner_user_id',
        'frequency',
        'run_time',
        'weekday',
        'day_of_month',
        'timezone',
        'recipients',
        'format',
        'delivery',
        'run_token',
        'token_expires_at',
        'next_run_at',
        'is_active',
    ];

    protected $hidden = [
        'run_token',
    ];

    protected function casts(): array
    {
        return [
            'template_id' => 'integer',
            'owner_user_id' => 'integer',
            'weekday' => 'integer',
            'day_of_month' => 'integer',
            'recipients' => 'array',
            'run_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'template_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ReportRun::class, 'schedule_id');
    }

    /**
     * The first run time strictly after $from, read in the schedule's timezone,
     * as a UTC instant. Weekly runs on `weekday` (0 = Sunday); monthly on
     * `day_of_month`, or the month's last day when it is shorter.
     */
    public function computeNextRunAt(Carbon $from): Carbon
    {
        $local = CarbonImmutable::instance($from)->setTimezone($this->timezone);
        [$hour, $minute] = array_map('intval', explode(':', (string) $this->run_time));
        $at = fn (CarbonImmutable $day) => $day->setTime($hour, $minute);

        $next = match ($this->frequency) {
            'daily' => $at($local),
            'weekly' => $at($local->addDays(($this->weekday - $local->dayOfWeek + 7) % 7)),
            'monthly' => $at($local->setDay(min($this->day_of_month, $local->daysInMonth))),
        };

        if ($next <= $local) {
            $next = match ($this->frequency) {
                'daily' => $at($local->addDay()),
                'weekly' => $next->addWeek(),
                'monthly' => $at(($month = $local->startOfMonth()->addMonth())->setDay(min($this->day_of_month, $month->daysInMonth))),
            };
        }

        return Carbon::instance($next)->utc();
    }

    /**
     * Asks pizzasys for this schedule's token as the user behind $authorization
     * (the owner's "Bearer …" header) and keeps it; pizzasys replaces any older
     * one. False when pizzasys does not hand one out.
     */
    public function mintToken(#[\SensitiveParameter] string $authorization): bool
    {
        try {
            $response = $this->pizzasys($authorization)->post('/api/v1/auth/report-schedule-tokens', ['schedule_id' => $this->id]);
        } catch (ConnectionException) {
            return false;
        }

        $token = $response->json('data.token');
        if ($response->status() !== 201 || ! is_string($token) || $token === '') {
            return false;
        }

        $expires = $response->json('data.expires_at');
        $this->run_token = $token;
        $this->token_expires_at = is_string($expires) && $expires !== '' ? Carbon::parse($expires) : null;

        return true;
    }

    /** Revokes this schedule's token in pizzasys; true once it is gone (204, or 404: never there). */
    public function revokeToken(#[\SensitiveParameter] string $authorization): bool
    {
        try {
            $status = $this->pizzasys($authorization)->delete("/api/v1/auth/report-schedule-tokens/{$this->id}")->status();
        } catch (ConnectionException) {
            return false;
        }

        return in_array($status, [204, 404], true);
    }

    /**
     * pizzasys works out the token's abilities from the user's rules, so it gets longer than a token check.
     * The paths are full (/api/v1/...), so any /api... the base URL ends with is dropped, as User does:
     * AUTH_SERVER_BASE_URL may be the bare host or end in /api/v1.
     */
    private function pizzasys(#[\SensitiveParameter] string $authorization): PendingRequest
    {
        $host = preg_replace('~/api(?:/.*)?$~i', '', rtrim((string) config('services.auth_server.base_url'), '/'));

        return Http::baseUrl(rtrim((string) $host, '/'))
            ->withHeaders(['Authorization' => $authorization])
            ->acceptJson()
            ->timeout(10);
    }
}
