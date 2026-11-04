<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Report schedules: a template run for its owner on a daily, weekly or monthly
 * clock and delivered to explicit users. Each schedule holds its own pizzasys
 * token, minted with the owner's bearer when the schedule is saved and revoked
 * before it is deleted. A user sees and changes only their own schedules; for
 * anyone else a schedule does not exist (404).
 *
 * Schedule: {id, template_id, template_name, owner_user_id, frequency, run_time (HH:MM),
 *  weekday (0 = Sunday), day_of_month, timezone, recipients: {users: [ids]}, format,
 *  delivery, next_run_at, last_run_at (the latest run's creation), is_active,
 *  created_at, updated_at}
 */
class ReportScheduleController extends Controller
{
    /** GET /api/report-builder/schedules — the caller's own */
    public function index(): JsonResponse
    {
        return response()->json($this->mine()->orderBy('id')->get()->map(fn (ReportSchedule $schedule) => $this->present($schedule)));
    }

    /**
     * POST /api/report-builder/schedules
     * {template_id, frequency: daily|weekly|monthly, run_time: HH:MM, weekday? (weekly, 0-6),
     *  day_of_month? (monthly, 1-31), timezone?, recipients: {users: [ids]}, format: csv|xlsx,
     *  delivery: link|attachment|both, is_active?}
     *
     * The row, its token and its next run are one step: if pizzasys hands out no
     * token, or the row cannot be kept once it has one, nothing is kept (503), and
     * the token pizzasys may have made is given back (best effort).
     */
    public function store(Request $request): JsonResponse
    {
        $schedule = new ReportSchedule([...$this->validated($request->all()), 'owner_user_id' => (int) Auth::id()]);
        $authorization = (string) $request->header('Authorization');

        try {
            DB::transaction(function () use ($schedule, $authorization) {
                $schedule->save();
                if (! $schedule->mintToken($authorization)) {
                    throw new HttpResponseException($this->noToken());
                }
                $schedule->next_run_at = $schedule->computeNextRunAt(now());
                $schedule->save();
            });
        } catch (Throwable $e) {
            // Once the row has an id the mint was asked for: its answer may have been lost (a timeout)
            // or the token not kept, and the row is gone with the rollback.
            if ($schedule->id !== null) {
                $schedule->revokeToken($authorization);
            }

            throw $e instanceof HttpResponseException || $schedule->id === null ? $e : new HttpResponseException($this->notSaved());
        }

        return response()->json($this->present($this->mine()->findOrFail($schedule->id)), 201);
    }

    /** GET /api/report-builder/schedules/{schedule} */
    public function show(int $schedule): JsonResponse
    {
        return response()->json($this->present($this->mine()->findOrFail($schedule)));
    }

    /**
     * PUT /api/report-builder/schedules/{schedule}  the store body, or any part of it (owner only)
     *
     * The fields sent replace the stored ones and the whole is checked again; the
     * next run is recomputed. Pausing and resuming is this with `is_active` flipped.
     * The token is minted afresh (pizzasys replaces the old one, with the owner's
     * permissions as they are now) when the schedule has none or is being resumed;
     * 503 and nothing changed if pizzasys then hands out no token. If the new token
     * is made but the row cannot be saved, the token is given back (best effort) and
     * the schedule is left paused, 503: its stored token is no longer the one pizzasys
     * knows. The template is checked only when it is changed, so a schedule can still
     * be paused after its template went private.
     */
    public function update(Request $request, int $schedule): JsonResponse
    {
        $model = $this->mine()->findOrFail($schedule);
        $data = $this->validated([
            ...$model->only(['template_id', 'frequency', 'weekday', 'day_of_month', 'timezone', 'recipients', 'format', 'delivery', 'is_active']),
            'run_time' => substr((string) $model->run_time, 0, 5),
            ...$request->all(),
        ], unchangedTemplate: $model->template_id);
        $authorization = (string) $request->header('Authorization');

        $mint = $model->run_token === null || (! $model->is_active && $data['is_active']);
        if ($mint && ! $model->mintToken($authorization)) {
            return $this->noToken();
        }

        try {
            $model->fill($data);
            $model->next_run_at = $model->computeNextRunAt(now());
            $model->save();
        } catch (Throwable $e) {
            if (! $mint) {
                throw $e;
            }
            $model->revokeToken($authorization);
            try {
                ReportSchedule::query()->whereKey($model->id)->update(['is_active' => false]);
            } catch (Throwable) {
                // Nothing more can be done; the revoked token stops its runs.
            }

            return $this->notSaved();
        }

        return response()->json($this->present($this->mine()->findOrFail($model->id)));
    }

    /**
     * DELETE /api/report-builder/schedules/{schedule}  (owner only)
     *
     * Its token is revoked first; while pizzasys cannot confirm that, the
     * schedule stays (503). Its runs stay, no longer tied to it.
     */
    public function destroy(Request $request, int $schedule): JsonResponse
    {
        $model = $this->mine()->findOrFail($schedule);

        if (! $model->revokeToken((string) $request->header('Authorization'))) {
            return response()->json(['message' => 'Could not revoke this schedule\'s token at the auth server; try again shortly.'], 503);
        }
        $model->delete();

        return response()->json(['message' => 'Schedule deleted.']);
    }

    /** The caller's schedules, with what the response shows. */
    private function mine(): Builder
    {
        return ReportSchedule::query()
            ->where('owner_user_id', (int) Auth::id())
            ->with('template:id,name')
            ->withMax('runs as last_run_at', 'created_at');
    }

    private function noToken(): JsonResponse
    {
        return response()->json(['message' => 'Could not get a token for this schedule from the auth server; try again shortly.'], 503);
    }

    private function notSaved(): JsonResponse
    {
        return response()->json(['message' => 'Could not save this schedule; try again shortly.'], 503);
    }

    /**
     * The checked fields, ready to store; the template must be one the caller can read
     * (unless it is $unchangedTemplate, the one the schedule already has).
     */
    private function validated(array $input, ?int $unchangedTemplate = null): array
    {
        $data = Validator::make($input, [
            'template_id' => 'required|integer',
            'frequency' => 'required|string|in:daily,weekly,monthly',
            'run_time' => 'required|date_format:H:i',
            'weekday' => 'nullable|required_if:frequency,weekly|integer|between:0,6',
            'day_of_month' => 'nullable|required_if:frequency,monthly|integer|between:1,31',
            'timezone' => 'sometimes|string|timezone:all',
            'recipients' => 'required|array:users',
            'recipients.users' => 'required|array|min:1',
            'recipients.users.*' => 'required|integer|distinct|exists:users,id',
            'format' => 'required|string|in:csv,xlsx',
            'delivery' => 'required|string|in:link,attachment,both',
            'is_active' => 'sometimes|boolean',
        ])->validate();

        if ((int) $data['template_id'] !== $unchangedTemplate && ! ReportTemplate::query()->readableBy((int) Auth::id())->whereKey($data['template_id'])->exists()) {
            throw ValidationException::withMessages(['template_id' => 'The template does not exist or is not shared with you.']);
        }

        return [
            'template_id' => (int) $data['template_id'],
            'frequency' => $data['frequency'],
            'run_time' => $data['run_time'].':00',
            'weekday' => $data['frequency'] === 'weekly' ? (int) $data['weekday'] : null,
            'day_of_month' => $data['frequency'] === 'monthly' ? (int) $data['day_of_month'] : null,
            'timezone' => $data['timezone'] ?? 'America/New_York',
            'recipients' => ['users' => array_map('intval', $data['recipients']['users'])],
            'format' => $data['format'],
            'delivery' => $data['delivery'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function present(ReportSchedule $schedule): array
    {
        $values = $schedule->toArray();

        return [
            'id' => $schedule->id,
            'template_id' => $schedule->template_id,
            'template_name' => $schedule->template?->name,
            'owner_user_id' => $schedule->owner_user_id,
            'frequency' => $schedule->frequency,
            'run_time' => substr((string) $schedule->run_time, 0, 5),
            'weekday' => $schedule->weekday,
            'day_of_month' => $schedule->day_of_month,
            'timezone' => $schedule->timezone,
            'recipients' => $schedule->recipients,
            'format' => $schedule->format,
            'delivery' => $schedule->delivery,
            'next_run_at' => $values['next_run_at'] ?? null,
            'last_run_at' => $values['last_run_at'] ?? null,
            'is_active' => $schedule->is_active,
            'created_at' => $values['created_at'] ?? null,
            'updated_at' => $values['updated_at'] ?? null,
        ];
    }
}
