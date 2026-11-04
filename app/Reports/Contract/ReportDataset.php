<?php
// Copied from LC_PIZZA_DATA/app/Reports/Contract/ReportDataset.php — contract v1. Edit there, copy everywhere.

declare(strict_types=1);

namespace App\Reports\Contract;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A table (or join) a service offers to the report builder. Everything a
 * request may touch is listed in columns(); nothing else reaches the SQL.
 *
 * The grain keys (`store`, `date`, `employee`) are ordinary columns with those
 * keys. `store` always comes out as the store code (`03795-00044`); `employee`
 * is a Hiring employee id, so a dataset keyed by users never declares it.
 */
abstract class ReportDataset
{
    public const CONTRACT_VERSION = 1;

    public const KEY = '';

    /** Hashed in this order into contract_sha1, so the engine sees a copy that drifted. */
    private const CONTRACT_FILES = [
        'Reports/Contract/ReportDataset.php',
        'Reports/Contract/Column.php',
        'Reports/Contract/DatasetQueryCompiler.php',
    ];

    abstract public function label(): string;

    /** @return list<'store'|'date'|'employee'> */
    abstract public function grain(): array;

    /** @return list<Column> */
    abstract public function columns(): array;

    abstract public function baseQuery(): Builder;

    /** SQL the store filter runs on; storeKeys() gives the values it is compared with. */
    abstract public function storeColumn(): string;

    /**
     * SQL of the local date the period filter runs on. Null for a snapshot
     * dataset (current state, e.g. stock on hand): it ignores the period and
     * its grain never holds `date`.
     */
    abstract public function dateColumn(): ?string;

    public function heavy(): bool
    {
        return false;
    }

    /**
     * Store codes from the wire to the values storeColumn() holds. Most
     * services keep a numeric store_id and a `stores` replica keyed by code.
     *
     * @param  list<string>  $codes
     */
    public function storeKeys(array $codes): array
    {
        return DB::table('stores')->whereIn('store_number', $codes)->pluck('id')->all();
    }

    public static function contractSha1(): string
    {
        $source = '';
        foreach (self::CONTRACT_FILES as $file) {
            $source .= str_replace("\r\n", "\n", (string) file_get_contents(app_path($file)));
        }

        return sha1($source);
    }

    public function toArray(): array
    {
        return [
            'key' => static::KEY,
            'label' => $this->label(),
            'path' => '/report-datasets/'.static::KEY.'/query',
            'grain' => $this->grain(),
            'heavy' => $this->heavy(),
            'dated' => $this->dateColumn() !== null,
            'columns' => array_map(fn (Column $column) => $column->toArray(), $this->columns()),
        ];
    }
}
