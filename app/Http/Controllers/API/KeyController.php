<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\StoreKeyRequest;
use App\Http\Requests\DataEntry\UpdateKeyRequest;
use App\Models\EnteredKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class KeyController extends Controller
{
    public function index(): JsonResponse
    {
        $tags = request()->query('tags');

        $query = EnteredKey::with(['storeRules', 'tags'])
            ->orderBy('id', 'desc');

        if ($tags) {

            $tagIds = explode(',', $tags);

            $query->whereHas('tags', function ($q) use ($tagIds) {
                $q->whereIn('tags.id', $tagIds);
            });
        }

        $keys = $query->paginate();

        return response()->json($keys);
    }

    public function store(StoreKeyRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['store_rules'] = $this->normalizeStoreRules($payload['store_rules'] ?? []);

        $key = DB::transaction(function () use ($payload) {
            $key = EnteredKey::create([
                'label' => $payload['label'],
                'data_type' => $payload['data_type'],
                'is_active' => $payload['is_active'] ?? true,
            ]);

            foreach ($payload['store_rules'] as $rulePayload) {
                $time = $rulePayload['time'];

                unset($rulePayload['time']);

                $rulePayload['due_time'] = strlen($time) === 5 ? $time . ':00' : $time;

                $key->storeRules()->create($rulePayload);
            }

            if (!empty($payload['tags'])) {
                $key->tags()->sync($payload['tags']);
            }

            return $key->load(['storeRules', 'tags']);
        });

        return response()->json($key, 201);
    }

    public function show(EnteredKey $key): JsonResponse
    {
        return response()->json($key->load(['storeRules', 'tags']));
    }

    public function update(UpdateKeyRequest $request, EnteredKey $key): JsonResponse
    {
        $payload = $request->validated();
        $payload['store_rules'] = $this->normalizeStoreRules($payload['store_rules'] ?? []);

        $key = DB::transaction(function () use ($payload, $key) {

            $key->update([
                'label' => $payload['label'],
                'data_type' => $payload['data_type'],
                'is_active' => $payload['is_active'] ?? $key->is_active,
            ]);

            $key->storeRules()->delete();
            $key->storeRules()->createMany($payload['store_rules']);

            if (array_key_exists('tags', $payload)) {
                $key->tags()->sync($payload['tags'] ?? []);
            }

            return $key->load(['storeRules', 'tags']);
        });

        return response()->json($key);
    }

    public function destroy(EnteredKey $key): JsonResponse
    {
        $key->update(['is_active' => false]);
        return response()->json(['message' => 'Key deactivated.']);
    }

    public function restore(EnteredKey $key): JsonResponse
    {
        $key->update(['is_active' => true]);
        return response()->json(['message' => 'Key reactivated.']);
    }

    public function forceDelete(EnteredKey $key): JsonResponse
    {
        $key->delete();
        return response()->json(['message' => 'Key permanently deleted.']);
    }

    private function normalizeStoreRules(array $rules): array
    {
        return array_map(function (array $rule) {
            $fillMode = $rule['fill_mode'] ?? 'store_once';

            $normalized = [
                ...$rule,
                'fill_mode' => $fillMode,
                'role_names' => $fillMode === 'role_each'
                    ? array_values(array_unique(array_filter($rule['role_names'] ?? [], fn($v) => filled($v))))
                    : null,
            ];

            return $normalized;
        }, $rules);
    }
}