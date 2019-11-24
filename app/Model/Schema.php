<?php

namespace App\Model;
use DB;
use File;
use Config;
use Illuminate\Support\Str;

class Schema
{
    protected $schemaLoaded = false;
    protected $errors;
    protected $schema = null;
    protected $table = null;

    public function __construct($schemaPath)
    {
        $this->errors = collect([]);

        $this->loadSchemaFile($schemaPath);
    }

    /**
     * Load Schema File
     * @param  string $schemaPath schame.json file path
     * @return void
     */
    protected function loadSchemaFile($schemaPath): void
    {
        try {
            $this->schemaFile = File::get($schemaPath);
            $this->schemaLoaded = true;
        } catch (\Exception $exception) {
            $this->pushToErrors("Can't find a file with the path: {$schemaPath}");
        }
    }

    /**
     * Addes databases connections to config and tests the connections
     * @return void
     */
    public function initDBConnections(): void
    {
        $this->addConnectionsToConfig();
        $this->testConnections();
    }

    /**
     * Process the migration
     * @return void
     */
    public function migrate(): void
    {
        $tables = collect($this->schema->tables);
        $tables->map(function($table) {
            $this->migrateTable($table);
        });
    }

    /**
     * Handles table migrations
     * @param  object $table object of table schema
     * @return void
     */
    protected function migrateTable(object $table): void
    {
        $this->table = $table;

        $remove = isset($table->remove) ? $table->remove : [];

        $data = $this->normalizeDataToInsert(
            $this->loadTabelData(
                array_merge($remove, $this->extractFromColumns($table->columns))
            ),
            $table->columns,
            $remove,
        );

        $this->insert($data);
    }

    /**
     * Checks for constrants and table truncation and UUID generation
     * Inserts migrated data to database
     * @param  array $data array of data to be inserted
     * @return void
     */
    protected function insert(array $data): void
    {
        if (isset($this->table->disableForeignkeyCheck) && $this->table->disableForeignkeyCheck) {
            DB::connection('c2')->statement('SET FOREIGN_KEY_CHECKS=0;');
        }

        if (isset($this->table->shouldTruncateFirst) && $this->table->shouldTruncateFirst) {
            DB::connection('c2')->table($this->table->to)->truncate();
        }

        if (isset($this->table->generateUuid) && $this->table->generateUuid) {
            $data = $this->generateUuid($data);
        }
        DB::connection('c2')->table($this->table->to)->insert($data);
    }

    /**
     * Add UUID to record
     * @param  array $data
     * @return array same as input array with uuid
     */
    protected function generateUuid($data)
    {
        return array_map(function($record) {
            $record['uuid'] = Str::uuid();
            return $record;
        }, $data);
    }

    /**
     * Normalize data by renaming and removing un-needed columns
     * @param  array $data    array of database records
     * @param  array  $columns columns masking array
     * @param  array  $remove  array of columns to be removed
     * @return array          array of normalized data
     */
    protected function normalizeDataToInsert($data, array $columns, array $remove = [])
    {
        return $data->map(function ($record) use ($columns, $remove) {
            return $this->renameAndRemove($record, $columns, $remove);
        })->toArray();
    }

    /**
     * Rename columns and remove un-needed columns
     * @param  object $object  database record
     * @param  array  $columns array of columns masks
     * @param  array  $remove  array of columns to be removed
     * @return array           array of normalized record
     */
    protected function renameAndRemove($object, array $columns, array $remove)
    {
        foreach ($columns as $column) {
            if ($column[0] !== $column[1]) {
                $object->{$column[1]} = $object->{$column[0]};
                unset($object->{$column[0]});
            }
        }

        foreach ($remove as $column) {
            unset($object->{$column});
        }

        return (array) $object;
    }

    /**
     * Loads table data from database
     * @param  array $columns   array of columns to be loaded
     * @return Object           object of table data
     */
    protected function loadTabelData($columns): object
    {
        return DB::connection('c1')->table($this->table->from)->select($columns)->get();
    }

    /**
     * Extract "From" Columns
     * @param  array $columns array of columns masks
     * @return array          array of "from" columns
     */
    protected function extractFromColumns(array $columns): array
    {
        return array_map(function ($column) {
            return $column[0];
        }, $columns);
    }

    /**
     * Extract "To" Columns
     * @param  array $columns array of columns masks
     * @return array          array of "to" columns
     */
    protected function extractToColumns($columns): array
    {
        return array_map(function ($column) {
            return $column[1];
        }, $columns);
    }

    /**
     * Add databases Connections to config
     */
    protected function addConnectionsToConfig(): void
    {
        Config::set('database.connections.c1', (array) $this->schema->connections->from);
        Config::set('database.connections.c2', (array) $this->schema->connections->to);
    }

    /**
     * Tests DB Connections by running simple query
     */
    protected function testConnections(): void
    {
        try {
            DB::connection('c1')->statement('show tables');
        } catch (\Exception $exception) {
            $this->pushToErrors("DB Connection Failed: Error in 'from' database connection");
        }

        try {
            DB::connection('c2')->statement('show tables');
        } catch (\Exception $exception) {
            $this->pushToErrors("DB Connection Failed: Error in 'to' database connection");
        }
    }

    /**
     * Parses Schemafile
     */
    public function parse(): void
    {
        $this->schema = (object) json_decode($this->schemaFile);
    }

    /**
     * Validates schema file structure
     */
    public function validateSchema(): void
    {
        $schemaStructure = [
            'connections' => [
                // 'from' => [
                //     'host', 'name', 'user', 'password'
                // ],
                // 'to' => [
                //     'host', 'name', 'user', 'password'
                // ]
            ]
        ];
        $this->handleSchemaRequirmentsChecks($schemaStructure);
    }

    /**
     * Implode and flatten multi-dimensional array
     * @param  array  $array   multi-dimensional array
     * @param  string $glue    the glue string
     * @param  array  &$result array to save the result at
     * @param  string $path    keeps track of the path
     */
    protected function implodeMDArray(array $array, string $glue, &$result = [], $path = ''): void
    {
        foreach ($array as $key => $node) {
            if (is_array($node)) {
                $this->implodeMDArray(
                    $node,
                    $glue,
                    $result,
                    $path ? $path . $glue . $key : $key,
                );
            } else {
                $result[] = $path . $glue . $node;
            }
        }
    }

    /**
     * Applies schema checks
     * @param  array  $paths array of pathes to check
     */
    protected function handleSchemaRequirmentsChecks(array $paths): void
    {
        $pathes = [];
        $this->implodeMDArray($paths, '.', $pathes);

        foreach ($pathes as $path) {
            if ($this->has($path) !== true) {
                $this->pushToErrors("Schema Structure Error: {$path} value doesn't exist");
            }
        }
    }

    /**
     * Check if object has property on schema
     * @param  string  $path   path to check
     * @param  object  $object object to check on
     * @return string|bool     boolean if true and string if it fails
     */
    protected function has(string $path, object $object = null)
    {
        $parts = explode('.', $path, 2);

        if (count($parts) > 1) {
            return property_exists($object ?: $this->schema, $parts[0])
                ? $this->has($parts[1], ($object ?: $this->schema)->{$parts[0]})
                : $path;
        }

        return property_exists($object ?: $this->schema, $path) ? true : $path;
    }

    /**
     * Push strgin to errors array
     * @param  string $error
     */
    protected function pushToErrors(string $error): void
    {
        $this->errors->push($error);
    }

    /**
     * Checks if there's an error on the schema
     * @return bool
     */
    public function hasErrors(): bool
    {
        return $this->errors->count() ? true : false;
    }

    /**
     * Returens errors array
     * @return array array of errors
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Returns schemaLoaded value
     * @return bool
     */
    public function schemaLoaded(): bool
    {
        return $this->schemaLoaded;
    }
}