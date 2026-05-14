<?php

namespace App\Jobs;

use App\Models\DataOutboxEvent;
use App\Services\Nats\JetStreamPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PublishDataOutboxEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $outboxEventId
    ) {
    }

    public function handle(JetStreamPublisher $publisher): void
    {
        $event = DataOutboxEvent::query()->findOrFail($this->outboxEventId);

        if ($event->published_at !== null) {
            return;
        }

        try {
            $publisher->publish($event->subject, $event->payload);

            $event->update([
                'published_at' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $e) {
            $event->update([
                'attempts' => $event->attempts + 1,
                'last_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}