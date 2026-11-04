<?php

declare(strict_types=1);

namespace App\Reports\Engine;

use App\Reports\Contract\ReportDataset;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Talks the report dataset contract to every service, Data included (over
 * loopback, so its own auth rules apply too). The caller's bearer token is
 * forwarded, so each service authorizes the request as it would any other.
 *
 * A preview pages (query, within a deadline); a run streams (stream: every
 * row as NDJSON, no total timeout, only an idle read timeout).
 */
final class FederatedDatasetClient
{
    private const MAX_RETRIES = 3;

    private const PAGE = 5000;

    private const CATALOG_TTL = 600;

    /** Stores asked at once when "mine" falls back to one store per request. */
    private const STORES_AT_ONCE = 5;

    /** Bytes read from a stream at a time. */
    private const CHUNK = 65536;

    /**
     * catalogs(), cached for 10 minutes: the catalog is code, the same for
     * every user. Cached only when every service answered, so one that was
     * down is asked again next time.
     *
     * @return list<array>
     */
    public function cachedCatalogs(#[\SensitiveParameter] string $bearer): array
    {
        $key = 'report-builder:catalog:'.ReportDataset::contractSha1();

        if (($services = Cache::get($key)) !== null) {
            return $services;
        }

        $services = $this->catalogs($bearer);
        if (array_filter($services, fn (array $service) => isset($service['error'])) === []) {
            Cache::put($key, $services, self::CATALOG_TTL);
        }

        return $services;
    }

    /**
     * Every configured service's catalog, asked at once. A service that does
     * not answer, or speaks another contract version, is listed with `error`
     * (and, when it answered with an error, its HTTP `status`).
     *
     * Each entry is the service's own catalog, with its `service` (the pizzasys
     * name the dashboard hands to canAccessRoute) and, as `origin`, the key of
     * services.report_datasets.urls it was asked under.
     *
     * @return list<array>
     */
    public function catalogs(#[\SensitiveParameter] string $bearer): array
    {
        $urls = array_filter((array) config('services.report_datasets.urls', []));
        $timeout = (int) config('services.report_datasets.catalog_timeout', 5);

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (string $service) => $pool->as($service)->withToken($bearer)->acceptJson()->timeout($timeout)
                ->get(rtrim($urls[$service], '/').'/report-datasets'),
            array_keys($urls),
        ));

        $sha1 = ReportDataset::contractSha1();
        $services = [];
        foreach (array_keys($urls) as $service) {
            $response = $responses[$service];
            $version = $response instanceof Response ? (int) $response->json('contract_version') : null;
            $name = $response instanceof Response ? $response->json('service') : null;

            $services[] = match (true) {
                ! $response instanceof Response => ['service' => $service, 'origin' => $service, 'error' => "{$service} is not answering."],
                ! $response->ok() => ['service' => $service, 'origin' => $service, 'error' => "{$service} answered {$response->status()}.", 'status' => $response->status()],
                $version !== ReportDataset::CONTRACT_VERSION => ['service' => $service, 'origin' => $service, 'error' => "{$service} speaks report contract v{$version}, Data speaks v".ReportDataset::CONTRACT_VERSION.'.'],
                default => [...(array) $response->json(), 'service' => is_string($name) && $name !== '' ? $name : $service, 'origin' => $service]
                    + ($response->json('contract_sha1') === $sha1 ? [] : ['warning' => "{$service}'s copy of the report contract differs from Data's."]),
            };
        }

        return $services;
    }

    /**
     * Every page of one dataset query, up to $maxRows rows; `truncated` when
     * the cap stopped the paging. The whole call, retries included, ends by
     * $deadline (a microtime(true) instant) with a 504. The preview's path.
     *
     * @param  array  $dataset  catalog entry with its `service` (the urls key)
     * @return array{columns: list<array>, rows: list<list<mixed>>, truncated: bool}
     */
    public function query(array $dataset, array $body, #[\SensitiveParameter] string $bearer, float $deadline, int $maxRows): array
    {
        $url = $this->url($dataset);
        $columns = [];
        $rows = [];
        $cursor = null;

        do {
            $page = (array) $this->attempt($dataset, fn () => Http::withToken($bearer)->acceptJson()
                ->timeout(max($deadline - microtime(true), 0.001))
                ->post($url, [...$body, 'cursor' => $cursor, 'limit' => min(self::PAGE, $maxRows - count($rows))]), $deadline)->json();

            $version = (int) data_get($page, 'meta.contract_version');
            if ($version !== ReportDataset::CONTRACT_VERSION) {
                abort(502, "{$dataset['service']} answered {$dataset['key']} in report contract v{$version}, Data speaks v".ReportDataset::CONTRACT_VERSION.'.');
            }

            $columns = $page['columns'];
            array_push($rows, ...$page['rows']);
            $cursor = $page['next_cursor'] ?? null;
        } while ($cursor !== null && count($rows) < $maxRows);

        return ['columns' => $columns, 'rows' => $rows, 'truncated' => $cursor !== null];
    }

    /**
     * Every row of one dataset query in stream mode, each handed to $row as
     * [column key => value] as it is read, so nothing is held. No total
     * timeout: only services.report_datasets.read_timeout seconds without a
     * byte fails it. 429 and 503 are retried before the first row only.
     *
     * @param  callable(array): void  $row
     * @return int the rows read
     */
    public function stream(array $dataset, array $body, #[\SensitiveParameter] string $bearer, callable $row): int
    {
        $url = $this->url($dataset);

        return $this->read($dataset, $this->attempt($dataset, fn () => $this->streaming(Http::withToken($bearer))->post($url, [...$body, 'stream' => true])), $row);
    }

    /**
     * stream() once per store in $stores, STORES_AT_ONCE requests at a time,
     * for "mine" when the services refuse the stores together. A store the
     * service refuses (403) is left out and returned.
     *
     * @param  list<string>  $stores
     * @param  callable(array): void  $row
     * @return list<string> the stores left out
     */
    public function streamByStore(array $dataset, array $body, array $stores, #[\SensitiveParameter] string $bearer, callable $row): array
    {
        $url = $this->url($dataset);
        $skipped = [];

        foreach (array_chunk($stores, self::STORES_AT_ONCE) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $store) => $this->streaming($pool->as($store)->withToken($bearer))->post($url, [...$body, 'stores' => [$store], 'stream' => true]),
                $chunk,
            ));

            foreach ($chunk as $store) {
                $response = $responses[$store];
                if (! $response instanceof Response) {
                    abort(502, "{$this->where($dataset)} is not answering.");
                }
                if ($response->status() === 403) {
                    $skipped[] = $store;
                } elseif (in_array($response->status(), [429, 503], true)) {
                    // Busy: this store again on its own, with the usual retries.
                    $this->stream($dataset, [...$body, 'stores' => [$store]], $bearer, $row);
                } elseif ($response->successful()) {
                    $this->read($dataset, $response, $row);
                } else {
                    $this->refuse($dataset, $response);
                }
            }
        }

        return $skipped;
    }

    /** Built here from the key; a path the catalog supplies is never followed. */
    private function url(array $dataset): string
    {
        return rtrim((string) config("services.report_datasets.urls.{$dataset['service']}"), '/')
            .'/report-datasets/'.rawurlencode((string) $dataset['key']).'/query';
    }

    private function where(array $dataset): string
    {
        return "{$dataset['service']} ({$dataset['key']})";
    }

    /** The body read as it arrives, with no total timeout, only the idle one. */
    private function streaming(PendingRequest $request): PendingRequest
    {
        return $request->accept('application/x-ndjson')->timeout(0)->withOptions([
            'stream' => true,
            'read_timeout' => (float) config('services.report_datasets.read_timeout', 300),
        ]);
    }

    /**
     * A successful answer to $send; a 429 or 503 is retried up to 3 times after
     * its Retry-After (at most 10 s), as far as $deadline (if any) allows.
     */
    private function attempt(array $dataset, callable $send, ?float $deadline = null): Response
    {
        for ($attempt = 0; ; $attempt++) {
            if ($deadline !== null && $deadline - microtime(true) <= 0) {
                abort(504, 'The report ran out of time.');
            }

            try {
                $response = $send();
            } catch (ConnectionException) {
                $deadline !== null && microtime(true) >= $deadline
                    ? abort(504, 'The report ran out of time.')
                    : abort(502, "{$this->where($dataset)} is not answering.");
            }

            if (in_array($response->status(), [429, 503], true) && $attempt < self::MAX_RETRIES) {
                $wait = min(max((int) $response->header('Retry-After'), 1), 10);
                if ($deadline !== null && microtime(true) + $wait >= $deadline) {
                    abort(504, 'The report ran out of time.');
                }
                Sleep::for($wait)->seconds();

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            $this->refuse($dataset, $response);
        }
    }

    /**
     * An answer that is not a success, as the user may hear it.
     *
     * Only a 403's own wording is shown; for the rest the service's message could say anything.
     * A 401 means the service does not know this user yet; passing it on would log them out.
     * The service's answer stays on the 502 as its previous exception (a scheduled run
     * looks there for a 401: its token is no longer valid).
     */
    private function refuse(array $dataset, Response $response): never
    {
        $where = $this->where($dataset);
        $status = $response->status();

        if ($status === 422 && is_array($response->json('errors'))) {
            throw ValidationException::withMessages(collect($response->json('errors'))
                ->mapWithKeys(fn ($messages, $field) => ["{$dataset['key']}.{$field}" => $messages])
                ->all());
        }

        $message = $response->json('message');
        match ($status) {
            403 => abort(403, "{$dataset['key']}: ".(is_string($message) && $message !== '' ? $message : 'forbidden.')),
            429 => abort(429, "{$where} is busy; try again shortly.", ['Retry-After' => (string) max((int) $response->header('Retry-After'), 1)]),
            504 => abort(504, "{$where} took too long to answer."),
            default => throw new HttpException(502, "{$where} could not answer.", $response->toException()),
        };
    }

    /**
     * The NDJSON body of a stream, line by line: the columns line, the rows,
     * then the done line, whose count must match. An error line, a body that
     * ends without the done line, or one that stops arriving fails the call.
     */
    private function read(array $dataset, Response $response, callable $row): int
    {
        $where = $this->where($dataset);
        $body = $response->toPsrResponse()->getBody();
        $keys = null;
        $count = 0;
        $done = null;
        $buffer = '';

        $line = function (string $text) use ($where, &$keys, &$count, &$done, $row): void {
            if ($text === '' || $done !== null) {
                return;
            }
            try {
                $value = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                abort(502, "{$where} sent rows that are not NDJSON.");
            }

            if ($keys === null) {
                is_array($value) && is_array($value['columns'] ?? null)
                    ? $keys = array_column($value['columns'], 'key')
                    : abort(502, "{$where} sent rows without their columns.");
            } elseif (is_array($value) && array_is_list($value) && count($value) === count($keys)) {
                $row(array_combine($keys, $value));
                $count++;
            } elseif (isset($value['error'])) {
                abort(502, "{$where} failed part-way through its rows.");
            } elseif (($value['done'] ?? null) === true) {
                $done = (int) ($value['row_count'] ?? -1);
            } else {
                abort(502, "{$where} sent a row that does not match its columns.");
            }
        };

        while (! $body->eof()) {
            $chunk = $body->read(self::CHUNK);
            if ($chunk === '') {
                if ($body->getMetadata('timed_out')) {
                    abort(504, "{$where} sent nothing for ".config('services.report_datasets.read_timeout', 300).' seconds.');
                }

                continue;
            }

            $buffer .= $chunk;
            $start = 0;
            while (($end = strpos($buffer, "\n", $start)) !== false) {
                $line(substr($buffer, $start, $end - $start));
                $start = $end + 1;
            }
            $buffer = substr($buffer, $start);
        }
        $line(trim($buffer));

        if ($done === null) {
            abort(502, "{$where} stopped before it sent every row.");
        }
        if ($done !== $count) {
            abort(502, "{$where} said it sent {$done} rows but sent {$count}.");
        }

        return $count;
    }
}
