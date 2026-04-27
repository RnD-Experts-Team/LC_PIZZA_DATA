<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDebrief;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeDebriefController extends Controller
{

    /**
     * List debrief notes
     */
    public function index(Request $request, string $store_id): JsonResponse
    {

        $filters = $request->validate([
            'employee_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->where(fn($query) => $query->where('store_id', $store_id)),
            ],
            'date' => 'nullable|date_format:Y-m-d',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $q = EmployeeDebrief::query()
            ->where('store_id', $store_id)
            ->with(['author', 'employee', 'attachments']);

        if (isset($filters['employee_id'])) {

            $q->where('employee_id', $filters['employee_id']);

        }

        if (isset($filters['date'])) {

            $q->whereDate('date', $filters['date']);

        }

        $perPage = (int) ($filters['per_page'] ?? 50);

        return response()->json(
            $q->orderByDesc('date')->paginate($perPage)
        );
    }

    /**
     * Create note
     */
    public function store(Request $request, string $store_id): JsonResponse
    {

        $data = $request->validate([
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where(fn($query) => $query->where('store_id', $store_id)),
            ],
            'note' => 'required|string|max:5000',
            'date' => 'required|date_format:Y-m-d',
            'attachments' => 'sometimes|array',
            'attachments.*' => 'sometimes|file|max:10240',
        ]);

        $user = $request->user();

        $debrief = EmployeeDebrief::create([

            'store_id' => $store_id,

            'user_id' => $user->id,

            'employee_id' => $data['employee_id'],

            'note' => $data['note'],

            'date' => $data['date'],

        ]);

        if ($request->has('attachments') || $request->hasFile('attachments')) {
            $this->syncUploadedAttachments(
                $debrief,
                $this->normalizeFiles($request->file('attachments', []))
            );
        }

        return response()->json($debrief->load(['author', 'employee', 'attachments']), 201);
    }

    /**
     * Show single note
     */
    public function show(string $store_id, EmployeeDebrief $debrief): JsonResponse
    {

        if ($debrief->store_id !== $store_id) {

            abort(404);

        }

        return response()->json(
            $debrief->load(['author', 'employee', 'attachments'])
        );
    }

    /**
     * Delete note
     */
    public function destroy(string $store_id, EmployeeDebrief $debrief): JsonResponse
    {

        if ($debrief->store_id !== $store_id) {

            abort(404);

        }

        $debrief->delete();

        return response()->json([
            'message' => 'Debrief deleted.'
        ]);
    }

    /**
     * Create multiple notes
     */
    public function storeMultiple(Request $request, string $store_id): JsonResponse
    {
        $data = $request->validate([
            'debriefs' => 'required|array|min:1',
            'debriefs.*.employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where(fn($query) => $query->where('store_id', $store_id)),
            ],
            'debriefs.*.note' => 'required|string|max:5000',
            'debriefs.*.date' => 'required|date_format:Y-m-d',
            'debriefs.*.attachments' => 'sometimes|array',
            'debriefs.*.attachments.*' => 'sometimes|file|max:10240',
        ]);

        $user = $request->user();

        $created = DB::transaction(function () use ($data, $store_id, $request, $user) {
            $out = [];

            foreach ($data['debriefs'] as $index => $item) {
                $debrief = EmployeeDebrief::create([
                    'store_id' => $store_id,
                    'user_id' => $user->id,
                    'employee_id' => $item['employee_id'],
                    'note' => $item['note'],
                    'date' => $item['date'],
                ]);

                if (data_get($request->all(), "debriefs.$index.attachments") !== null || $request->hasFile("debriefs.$index.attachments")) {
                    $this->syncUploadedAttachments(
                        $debrief,
                        $this->normalizeFiles($request->file("debriefs.$index.attachments", []))
                    );
                }

                $out[] = $debrief->load(['author', 'employee', 'attachments']);
            }

            return $out;
        });

        return response()->json([
            'message' => 'Debriefs created successfully.',
            'items' => $created,
            'count' => count($created),
        ], 201);
    }

    private function syncUploadedAttachments(EmployeeDebrief $debrief, array $files): void
    {
        $debrief->attachments()->delete();

        if (empty($files)) {
            return;
        }

        $folder = 'employee-debriefs/' . $debrief->store_id . '/' . $debrief->date->format('Y-m-d');

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

        $debrief->attachments()->createMany($payload);
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