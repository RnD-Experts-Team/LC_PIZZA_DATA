<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\BulkUpsertValuesRequest;
use App\Http\Requests\DataEntry\FilterValuesRequest;
use App\Http\Requests\DataEntry\UpsertValueRequest;
use App\Models\EnteredKey;
use App\Models\EnteredKeyValue;
use App\Services\DataEntry\DueKeyResolverService;
use App\Services\DataEntry\ValueTypeService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ValueController extends Controller
{
    public function __construct(
        private readonly ValueTypeService $typeService,
        private readonly DueKeyResolverService $dueService,
    ) {
    }

    /**
     * POST /engine/stores/{store_id}/dates/{date}/values
     */
    public function upsertOne(UpsertValueRequest $request, string $store_id, string $date): JsonResponse
    {
        $payload = $request->validated();

        $key = EnteredKey::findOrFail($payload['key_id']);

        $this->typeService->assertMatchesKeyType($key, $payload);

        $rule = $key->storeRules()
            ->where('store_id', $store_id)
            ->first();

        if (!$rule) {
            return response()->json([
                'message' => 'This key is not configured for this store.'
            ], 422);
        }

        $identity = [
            'key_id' => $key->id,
            'store_id' => $store_id,
            'entry_date' => $date,
        ];

        if ($rule->fill_mode === 'role_each') {
            $identity['user_id'] = auth()->id();
        }

        $value = EnteredKeyValue::updateOrCreate(
            $identity,
            [
                'user_id' => auth()->id(),
                'value_text' => $payload['value_text'] ?? null,
                'value_number' => $payload['value_number'] ?? null,
                'value_boolean' => $payload['value_boolean'] ?? null,
                'value_json' => $payload['value_json'] ?? null,
                'note' => $payload['note'] ?? null,
            ]
        );

        if ($request->has('attachments') || $request->hasFile('attachments')) {
            $this->syncUploadedAttachments(
                $value,
                $this->normalizeFiles($request->file('attachments', []))
            );
        }

        $value->loadMissing('attachments');

        return response()->json($value);
    }

    /**
     * POST /engine/stores/{store_id}/dates/{date}/values/bulk
     */
    public function upsertBulk(BulkUpsertValuesRequest $request, string $store_id, string $date): JsonResponse
    {
        $payload = $request->validated();

        $saved = DB::transaction(function () use ($payload, $request, $store_id, $date) {

            $out = [];

            foreach ($payload['items'] as $index => $item) {

                $key = EnteredKey::findOrFail($item['key_id']);

                $this->typeService->assertMatchesKeyType($key, $item);

                $rule = $key->storeRules()
                    ->where('store_id', $store_id)
                    ->first();

                if (!$rule) {
                    continue;
                }

                $identity = [
                    'key_id' => $key->id,
                    'store_id' => $store_id,
                    'entry_date' => $date,
                ];

                if ($rule->fill_mode === 'role_each') {
                    $identity['user_id'] = auth()->id();
                }

                $value = EnteredKeyValue::updateOrCreate(
                    $identity,
                    [
                        'user_id' => auth()->id(),
                        'value_text' => $item['value_text'] ?? null,
                        'value_number' => $item['value_number'] ?? null,
                        'value_boolean' => $item['value_boolean'] ?? null,
                        'value_json' => $item['value_json'] ?? null,
                        'note' => $item['note'] ?? null,
                    ]
                );

                if (data_get($request->all(), "items.$index.attachments") !== null || $request->hasFile("items.$index.attachments")) {
                    $this->syncUploadedAttachments(
                        $value,
                        $this->normalizeFiles($request->file("items.$index.attachments", []))
                    );
                }

                $value->loadMissing('attachments');

                $out[] = $value;
            }

            return $out;
        });

        return response()->json([
            'items' => $saved
        ]);
    }

    /**
     * GET /engine/values (global listing)
     */
    public function index(FilterValuesRequest $request): JsonResponse
    {
        $v = $request->validated();

        $tags = $request->query('tags');
        $tagIds = $tags ? explode(',', $tags) : [];

        $q = EnteredKeyValue::query()
            ->with(['key.tags', 'attachments']);

        if (!empty($v['key_id']))
            $q->where('key_id', $v['key_id']);
        if (!empty($v['date']))
            $q->whereDate('entry_date', $v['date']);
        if (!empty($v['from']))
            $q->whereDate('entry_date', '>=', $v['from']);
        if (!empty($v['to']))
            $q->whereDate('entry_date', '<=', $v['to']);

        if (!empty($v['label']) || !empty($v['data_type']) || !empty($tagIds)) {
            $q->whereHas('key', function ($k) use ($v, $tagIds) {

                if (!empty($v['label']))
                    $k->where('label', 'like', '%' . $v['label'] . '%');

                if (!empty($v['data_type']))
                    $k->where('data_type', $v['data_type']);

                if (!empty($tagIds)) {
                    $k->whereHas('tags', function ($t) use ($tagIds) {
                        $t->whereIn('tags.id', $tagIds);
                    });
                }
            });
        }

        $perPage = (int) ($v['per_page'] ?? 50);

        return response()->json($q->orderByDesc('entry_date')->paginate($perPage));
    }

    /**
     * GET /engine/stores/{store_id}/values (store listing with extra filters + optional due_on)
     */
    public function storeIndex(FilterValuesRequest $request, string $store_id): JsonResponse
    {
        $v = $request->validated();

        $tags = $request->query('tags');
        $tagIds = $tags ? explode(',', $tags) : [];

        $q = EnteredKeyValue::query()
            ->with(['key.tags', 'attachments'])
            ->where('store_id', $store_id);

        if (!empty($v['key_id']))
            $q->where('key_id', $v['key_id']);
        if (!empty($v['date']))
            $q->whereDate('entry_date', $v['date']);
        if (!empty($v['from']))
            $q->whereDate('entry_date', '>=', $v['from']);
        if (!empty($v['to']))
            $q->whereDate('entry_date', '<=', $v['to']);

        if (!empty($v['label']) || !empty($v['data_type']) || !empty($tagIds)) {

            $q->whereHas('key', function ($k) use ($v, $tagIds) {

                if (!empty($v['label']))
                    $k->where('label', 'like', '%' . $v['label'] . '%');

                if (!empty($v['data_type']))
                    $k->where('data_type', $v['data_type']);

                if (!empty($tagIds)) {
                    $k->whereHas('tags', function ($t) use ($tagIds) {
                        $t->whereIn('tags.id', $tagIds);
                    });
                }
            });
        }

        if (!empty($v['frequency_type']) || !empty($v['interval'])) {
            $q->whereHas('key.storeRules', function ($r) use ($v, $store_id) {
                $r->where('store_id', $store_id);

                if (!empty($v['frequency_type']))
                    $r->where('frequency_type', $v['frequency_type']);

                if (!empty($v['interval']))
                    $r->where('interval', (int) $v['interval']);
            });
        }

        if (!empty($v['due_on'])) {

            $due = $this->dueService->dueForStoreOnDate(
                $store_id,
                Carbon::parse($v['due_on']),
                $tagIds
            );

            $dueKeyIds = $due->pluck('key_id')->all();

            $q->whereIn('key_id', $dueKeyIds);
        }

        $perPage = (int) ($v['per_page'] ?? 50);

        return response()->json($q->orderByDesc('entry_date')->paginate($perPage));
    }

    public function grid(string $store_id, string $date): JsonResponse
    {
        $date = Carbon::parse($date)->startOfDay();

        $tags = request()->query('tags');
        $tagIds = $tags ? explode(',', $tags) : [];

        $dueItems = app(DueKeyResolverService::class)
            ->dueForStoreOnDate($store_id, $date, $tagIds);

        $userIds = $dueItems
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->values();

        $users = \App\Models\User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        $grid = $dueItems->map(function ($item) use ($users) {

            $user = null;

            if (!empty($item['user_id'])) {
                $user = $users->get($item['user_id']);
            }

            return [
                'key_id' => $item['key_id'],
                'label' => $item['label'],
                'data_type' => $item['data_type'],

                'fill_mode' => $item['fill_mode'] ?? 'store_once',

                'user_id' => $item['user_id'] ?? null,
                'user_name' => $user?->name,

                'filled' => $item['filled'],

                'value' => $item['value'],
            ];
        });

        return response()->json([
            'store_id' => $store_id,
            'date' => $date->toDateString(),
            'grid' => $grid->values(),
        ]);
    }

    private function syncUploadedAttachments(EnteredKeyValue $value, array $files): void
    {
        $value->attachments()->delete();

        if (empty($files)) {
            return;
        }

        $folder = 'entered-key-values/' . $value->store_id . '/' . $value->entry_date->format('Y-m-d');

        $payload = [];

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $payload[] = [
                'file_path' => $file->store($folder, 'public'),
                'disk' => 'public',
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ];
        }

        if (empty($payload)) {
            return;
        }

        $value->attachments()->createMany($payload);
    }

    /**
     * @param UploadedFile|array<int, UploadedFile|array<int, UploadedFile>>|null $files
     * @return array<int, UploadedFile>
     */
    private function normalizeFiles(UploadedFile|array|null $files): array
    {
        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (!is_array($files)) {
            return [];
        }

        $normalized = [];

        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $normalized[] = $file;
                continue;
            }

            if (is_array($file)) {
                $normalized = array_merge($normalized, $this->normalizeFiles($file));
            }
        }

        return $normalized;
    }
}
