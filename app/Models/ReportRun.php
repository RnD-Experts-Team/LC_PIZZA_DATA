<?php

namespace App\Models;

use App\Reports\Engine\ReportFileWriter;
use App\Reports\Engine\ReportRunner;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One execution of a report template and the files it wrote. Readable by the
 * user who asked for it (a scheduled run: the schedule's owner) and by the
 * users listed in its schedule's recipients.
 */
class ReportRun extends Model
{
    use HasUlids;

    /** Days a run and its files are kept. */
    public const KEEP_DAYS = 7;

    protected $fillable = [
        'template_id',
        'schedule_id',
        'requested_by_user_id',
        'definition',
        'period_from',
        'period_to',
        'format',
        'status',
        'row_count',
        'csv_path',
        'xlsx_path',
        'file_size',
        'error',
        'meta',
        'started_at',
        'heartbeat_at',
        'finished_at',
        'expires_at',
    ];

    protected $hidden = [
        'csv_path',
        'xlsx_path',
    ];

    protected function casts(): array
    {
        return [
            'template_id' => 'integer',
            'schedule_id' => 'integer',
            'requested_by_user_id' => 'integer',
            'definition' => 'array',
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'row_count' => 'integer',
            'file_size' => 'integer',
            'meta' => 'array',
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'template_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class, 'schedule_id');
    }

    /** The name its file in $format is handed out under. */
    public function fileName(string $format): string
    {
        $name = Str::slug($this->template?->name ?? 'report') ?: 'report';

        return "{$name}-{$this->period_from->toDateString()}-{$this->period_to->toDateString()}.{$format}";
    }

    /** Whatever files the run has, written or half-written, its staging database included. */
    public function deleteFiles(): void
    {
        Storage::disk('local')->delete([...array_values(ReportFileWriter::paths($this->id)), ReportRunner::stagingPath($this->id)]);
    }
}
