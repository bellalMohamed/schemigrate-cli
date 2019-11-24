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

    protected function loadSchemaFile($schemaPath)
    {
        try {
            $this->schemaFile = File::get($schemaPath);
            $this->schemaLoaded = true;
        } catch (\Exception $e) {
            $this->pushToErrors("Can't find a file with the path: {$schemaPath}");
        }
    }

    public function initDBConnections()
    {
        $this->addConnectionsToConfig();
        $this->testConnections();
    }

    public function migrate()
    {
        $tables = collect($this->schema->tables);
        $tables->map(function($table) {
            $this->migrateTable($table);
        });
    }

    protected function migrateTable($table)
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

    protected function insert($data)
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

    protected function generateUuid($data)
    {
        return array_map(function($record) {
            $record['uuid'] = Str::uuid();
            return $record;
        }, $data);
    }

    protected function normalizeDataToInsert($data, array $columns, array $remove = [])
    {
        return $data->map(function ($record) use ($columns, $remove) {
            return $this->renameAndRemove($record, $columns, $remove);
        })->toArray();
    }

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

    protected function loadTabelData($columns)
    {
        return DB::connection('c1')->table($this->table->from)->select($columns)->get();
    }

    protected function extractFromColumns($columns)
    {
        return array_map(function ($column) {
            return $column[0];
        }, $columns);
    }

    protected function extractToColumns($columns)
    {
        return array_map(function ($column) {
            return $column[1];
        }, $columns);
    }

    protected function addConnectionsToConfig()
    {
        Config::set('database.connections.c1', (array) $this->schema->connections->from);
        Config::set('database.connections.c2', (array) $this->schema->connections->to);
    }

    protected function testConnections()
    {
        try {
            DB::connection('c1')->statement('show tables');
        } catch (\Exception $e) {
            $this->pushToErrors("DB Connection Failed: Error in 'from' database connection");
        }

        try {
            DB::connection('c2')->statement('show tables');
        } catch (\Exception $e) {
            $this->pushToErrors("DB Connection Failed: Error in 'to' database connection");
        }
    }

    public function parse()
    {
        $this->schema = (object) json_decode($this->schemaFile);
    }

    public function validateSchema()
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

    protected function implodeMDArray(array $array, string $glue, &$result = [], $path = '') {
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

    protected function handleSchemaRequirmentsChecks(array $paths)
    {
        $pathes = [];
        $this->implodeMDArray($paths, '.', $pathes);

        foreach ($pathes as $path) {
            if ($this->has($path) !== true) {
                $this->pushToErrors("Schema Structure Error: {$path} value doesn't exist");
            }
        }
    }

    protected function has($path, $object = null)
    {
        $parts = explode('.', $path, 2);

        if (count($parts) > 1) {
            return property_exists($object ?: $this->schema, $parts[0])
                ?  $this->has($parts[1], ($object ?: $this->schema)->{$parts[0]})
                : $path;
        }

        return property_exists($object ?: $this->schema, $path) ? true : $path;
    }

    protected function pushToErrors($error)
    {
        $this->errors->push($error);
    }

    public function hasErrors()
    {
        return $this->errors->count() ? true : false;
    }

    public function errors()
    {
        return $this->errors;
    }

    public function schemaLoaded()
    {
        return $this->schemaLoaded;
    }
}