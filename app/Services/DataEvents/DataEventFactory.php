<?php

namespace App\Services\DataEvents;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DataEventFactory
{
    public function make(
        string $type,
        array $data,
        ?Request $request = null,
        array $metaOverrides = []
    ): array {
        $type = $this->applyEnvironmentPrefix($type);

        $now = now()->utc()->toIso8601String();

        $meta = array_merge([
            'correlation_id' => $request?->headers->get('X-Correlation-Id') ?? (string) Str::uuid(),
            'causation_id' => $request?->headers->get('X-Causation-Id'),
            'actor_user_id' => optional($request?->user())->id,
            'actor_type' => $request?->user() ? 'user' : 'service_client',
            'actor_ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ], $metaOverrides);

        return [
            'specversion' => '1.0',
            'id' => (string) Str::ulid(),
            'type' => $type,
            'source' => 'data-system',
            'subject' => $type,
            'time' => $now,
            'datacontenttype' => 'application/json',
            'data' => $data,
            'meta' => $meta,
        ];
    }

    private function applyEnvironmentPrefix(string $subject): string
    {
        if (!config('nats.dev_mode')) {
            return $subject;
        }

        if (str_starts_with($subject, 'data.v1.')) {
            return str_replace('data.v1.', 'data.testing.v1.', $subject);
        }

        if (str_starts_with($subject, 'notifications.v1.')) {
            return str_replace('notifications.v1.', 'notifications.testing.v1.', $subject);
        }

        return $subject;
    }
}