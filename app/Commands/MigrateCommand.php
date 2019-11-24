<?php

namespace App\Commands;

use App\Model\Schema;
use File;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class MigrateCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'migrate {schema=: .json file contains the migration schema}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Migrate data between two databases';

    protected $schema = null;

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        $this->schema = new Schema(
            $this->argument('schema')
        );

        $this->task("Validate Schema File Path", function () {
            if (!$this->isSchemaLoaded()) {
                $this->printErrors();
                return false;
            }
        });

        $this->task("Parse Schema", function () {
            $this->schema->parse();
        });

        $this->task("Validate Schema", function () {
            $this->schema->validateSchema();
            if ($this->schema->hasErrors()) {
                $this->printErrors();
                return false;
            }
        });

        $this->task("Checking DB Connections", function () {
            $this->schema->initDBConnections();
            if ($this->schema->hasErrors()) {
                $this->printErrors();
                return false;
            }
        });

        $this->task("Migrating", function () {
            $start = microtime(true);

            $this->schema->migrate();

            $time = round(microtime(true) - $start, 2);

            $this->notify("Migrated Successfully", "Time: {$time} seconds");
        });
    }

    /**
     * Check if schema is loaded or not
     *
     * @return bool
     */
    protected function isSchemaLoaded(): bool
    {
        return $this->schema->schemaLoaded();
    }

    /**
     * Prints schema errors
     *
     * @return void
     */
    protected function printErrors(): void
    {
        $this->schema->errors()->map(function ($error) {
            $this->error("\n{$error}");
        });
    }
}
