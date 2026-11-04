<?php

namespace Tests\Concerns;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

/**
 * A faked dataset service answering in stream mode, as DatasetQueryCompiler::stream
 * does: the columns line, one array per row, then the done line. The body is made
 * as it is read, so a test of any size holds no more of it than the client does.
 */
trait AnswersReportStreams
{
    /**
     * @param  list<string>  $keys
     * @param  iterable<list<mixed>>  $rows
     * @param  ?string  $end  the last line; null: the done line with the row count
     */
    protected function ndjson(array $keys, iterable $rows, ?string $end = null): PromiseInterface
    {
        $lines = (function () use ($keys, $rows, $end) {
            yield json_encode(['columns' => array_map(fn (string $key) => ['key' => $key, 'type' => 'string'], $keys)])."\n";
            $count = 0;
            foreach ($rows as $row) {
                $count++;
                yield json_encode($row)."\n";
            }
            yield $end ?? json_encode(['done' => true, 'row_count' => $count])."\n";
        })();

        return Create::promiseFor(new Response(200, ['Content-Type' => 'application/x-ndjson'], Utils::streamFor($lines)));
    }

    /** The output keys a dataset query asks for: its group keys, then its aggregate aliases. */
    protected function keysOf($request): array
    {
        return array_values(array_unique([...$request['columns'] ?? [], ...$request['group_by'] ?? [], ...array_column($request['aggregates'] ?? [], 'alias')]));
    }
}
