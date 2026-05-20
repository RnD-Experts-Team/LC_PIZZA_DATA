<?php

namespace App\Services\DataEvents;

use App\Models\DataOutboxEvent;

class DataOutboxService
{
    public function record(string $subject, array $payload): DataOutboxEvent
    {
        $subject = $this->applyEnvironmentPrefix($subject);

        return DataOutboxEvent::create([
            'subject' => $subject,
            'type' => $subject,
            'payload' => $payload,
        ]);
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