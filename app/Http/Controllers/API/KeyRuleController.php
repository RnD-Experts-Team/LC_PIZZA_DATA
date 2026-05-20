<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\UpdateKeyRequest;
use App\Models\EnteredKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class KeyRuleController extends Controller
{
    public function index(EnteredKey $key): JsonResponse
    {
        return response()->json($key->storeRules()->orderBy('store_id')->get());
    }

    public function replace(UpdateKeyRequest $request, EnteredKey $key): JsonResponse
    {
        $payload = $request->validated();
        $storeRules = $this->normalizeStoreRules($payload['store_rules'] ?? []);

        $key = DB::transaction(function () use ($storeRules, $key) {
            $key->storeRules()->delete();
            $key->storeRules()->createMany($storeRules);

            return $key->load('storeRules');
        });

        return response()->json($key->storeRules);
    }

    private function normalizeStoreRules(array $rules): array
    {
        return array_map(function (array $rule) {
            $fillMode = $rule['fill_mode'] ?? 'store_once';
            $time = $rule['time'] ?? null;
            $dueTime = null;

            if (filled($time)) {
                $dueTime = strlen($time) === 5 ? $time . ':00' : $time;
            }

            $normalized = [
                ...$rule,
                'fill_mode' => $fillMode,
                'due_time' => $dueTime,
                'role_names' => $fillMode === 'role_each'
                    ? array_values(array_unique(array_filter($rule['role_names'] ?? [], fn($v) => filled($v))))
                    : null,
            ];

            unset($normalized['time']);

            return $normalized;
        }, $rules);
    }
}