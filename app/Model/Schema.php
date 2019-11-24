<?php

namespace App\Model;
use DB;
use File;
use Config;

class Schema
{
    protected $schemaLoaded = false;
    protected $errors;
    protected $schema = null;

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