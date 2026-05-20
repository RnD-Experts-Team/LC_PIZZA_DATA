<?php

namespace App\Services\Nats;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Exception;
use Throwable;

class JetStreamPublisher
{
    private function makeClient(): Client
    {
        $host = (string) config('nats.host');
        $port = (int) config('nats.port');

        if ($host === '' || $port <= 0) {
            throw new Exception('NATS host/port not configured.');
        }

        $token = config('nats.token');
        $user = config('nats.user');
        $pass = config('nats.pass');

        $opts = [
            'host' => $host,
            'port' => $port,
        ];

        if (!empty($token)) {
            $opts['token'] = (string) $token;
        } elseif (!empty($user) || !empty($pass)) {
            if (empty($user) || empty($pass)) {
                throw new Exception('NATS user/pass requires both user and pass.');
            }

            $opts['user'] = (string) $user;
            $opts['pass'] = (string) $pass;
        } else {
            throw new Exception('NATS auth not configured.');
        }

        return new Client(new Configuration($opts));
    }

    public function publish(string $subject, array $payload): array
    {
        $this->assertSubjectAllowed($subject);

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new Exception('Failed to encode payload as JSON.');
        }

        $streamName = (string) config('nats.jetstream.stream');

        if ($streamName === '') {
            throw new Exception('Missing nats.jetstream.stream.');
        }

        $client = $this->makeClient();

        try {
            $stream = $client->getApi()->getStream($streamName);
            $ack = $stream->put($subject, $json);

            if (!$ack) {
                throw new Exception('JetStream publish did not return ACK.');
            }

            return $this->normalizeAck($ack);
        } catch (Throwable $e) {
            throw new Exception(
                "JetStream publish failed for subject '{$subject}': " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    private function normalizeAck(mixed $ack): array
    {
        if (is_array($ack)) {
            return $ack;
        }

        if (is_string($ack)) {
            $decoded = json_decode($ack, true);
            return is_array($decoded) ? $decoded : ['raw' => $ack];
        }

        if (is_object($ack)) {
            if (method_exists($ack, 'toArray')) {
                $arr = $ack->toArray();
                return is_array($arr) ? $arr : ['raw' => (string) $ack];
            }

            $arr = get_object_vars($ack);

            if (!empty($arr)) {
                return $arr;
            }

            if ($ack instanceof \JsonSerializable) {
                $arr = $ack->jsonSerialize();
                return is_array($arr) ? $arr : ['raw' => json_encode($arr)];
            }

            return ['raw' => (string) $ack];
        }

        return ['raw' => $ack];
    }

    private function assertSubjectAllowed(string $subject): void
    {
        $patterns = (array) config('nats.jetstream.subjects', []);

        if (empty($patterns)) {
            return;
        }

        foreach ($patterns as $pattern) {
            if ($this->matchesNatsSubject($subject, (string) $pattern)) {
                return;
            }
        }

        throw new Exception("Subject '{$subject}' is not allowed.");
    }

    private function matchesNatsSubject(string $subject, string $pattern): bool
    {
        $s = explode('.', $subject);
        $p = explode('.', $pattern);

        $si = 0;
        $pi = 0;

        while ($pi < count($p)) {
            $pt = $p[$pi];

            if ($pt === '>') {
                return $pi === count($p) - 1;
            }

            if ($si >= count($s)) {
                return false;
            }

            if ($pt !== '*' && $pt !== $s[$si]) {
                return false;
            }

            $si++;
            $pi++;
        }

        return $si === count($s);
    }
}