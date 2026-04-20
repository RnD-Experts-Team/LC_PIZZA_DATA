<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Employee;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class EmployeeCreatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $employeePayload = $this->extractEmployeePayload($event);

        $id = $this->resolveEmployeeId($event, $employeePayload);
        if ($id <= 0) {
            throw new \Exception('EmployeeCreatedHandler: missing/invalid employee id');
        }

        $firstName = $this->stringOrNull(data_get($employeePayload, 'first_name'));
        $lastName = $this->stringOrNull(data_get($employeePayload, 'last_name'));
        $middleName = $this->stringOrNull(data_get($employeePayload, 'middle_name'));

        if ($firstName === null || $lastName === null) {
            throw new \Exception('EmployeeCreatedHandler: missing first_name or last_name');
        }

        $storeNumber = $this->resolveLatestStoreNumber($event, $employeePayload);
        if ($storeNumber === null || !str_starts_with($storeNumber, '03795')) {
            throw new \Exception('EmployeeCreatedHandler: missing/invalid store_number (must start with 03795)');
        }

        $active = $this->resolveActiveFromLatestStatus($event, $employeePayload);

        DB::transaction(function () use ($id, $firstName, $middleName, $lastName, $storeNumber, $active) {
            Employee::query()->updateOrCreate(
                ['id' => $id],
                [
                    'first_name' => $firstName,
                    'middle_name' => $middleName,
                    'last_name' => $lastName,
                    'store_id' => $storeNumber,
                    'active' => $active,
                ]
            );
        });
    }

    private function extractEmployeePayload(array $event): array
    {
        $employee = data_get($event, 'data.employee');
        if (is_array($employee)) {
            return $employee;
        }

        $employee = data_get($event, 'employee');
        if (is_array($employee)) {
            return $employee;
        }

        return [];
    }

    private function resolveEmployeeId(array $event, array $employeePayload): int
    {
        $id = $this->asInt(data_get($employeePayload, 'id'));
        if ($id > 0) {
            return $id;
        }

        return $this->asInt(
            data_get($event, 'data.employee_id')
            ?? data_get($event, 'employee_id')
        );
    }

    private function resolveLatestStoreNumber(array $event, array $employeePayload): ?string
    {
        $stores = data_get($employeePayload, 'stores');
        if (!is_array($stores)) {
            $stores = data_get($event, 'data.changed_fields.stores.to');
        }
        if (!is_array($stores)) {
            $stores = [];
        }

        $latest = $this->latestEntry($stores);
        $storeNumber = $this->stringOrNull(data_get($latest, 'store.store_number'));

        if ($storeNumber !== null && str_starts_with($storeNumber, '03795')) {
            return $storeNumber;
        }

        $fallback = $this->stringOrNull(data_get($event, 'data.store_number') ?? data_get($event, 'store_number'));
        if ($fallback !== null && str_starts_with($fallback, '03795')) {
            return $fallback;
        }

        return null;
    }

    private function resolveActiveFromLatestStatus(array $event, array $employeePayload): bool
    {
        $statuses = data_get($employeePayload, 'status_histories');
        if (!is_array($statuses)) {
            $statuses = data_get($event, 'data.changed_fields.status_histories.to');
        }
        if (!is_array($statuses)) {
            $statuses = [];
        }

        $latest = $this->latestEntry($statuses);
        $status = strtolower((string) data_get($latest, 'status', ''));

        return in_array($status, ['hired', 'rehired'], true);
    }

    private function latestEntry(array $items): ?array
    {
        $latest = null;
        $latestTs = null;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $ts = $this->timestampFromEntry($item);

            if ($latest === null || ($ts !== null && ($latestTs === null || $ts > $latestTs))) {
                $latest = $item;
                $latestTs = $ts;
            }
        }

        return $latest;
    }

    private function timestampFromEntry(array $entry): ?int
    {
        $candidates = [
            data_get($entry, 'effective_date'),
            data_get($entry, 'created_at'),
            data_get($entry, 'updated_at'),
        ];

        foreach ($candidates as $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $ts = strtotime($value);
            if ($ts !== false) {
                return $ts;
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            return $value === '' ? null : $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return null;
    }

    private function asInt(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }

        if (is_string($v) && ctype_digit($v)) {
            return (int) $v;
        }

        if (is_numeric($v)) {
            return (int) $v;
        }

        return 0;
    }
}