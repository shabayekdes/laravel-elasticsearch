<?php

namespace Shabayek\Elastic\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Searchable;

/**
 * @deprecated Use "php artisan scout:index" instead. This command will be removed in 2.0.
 */
class ElasticSearchIndex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:elasticsearch:create {model : The class name of a searchable model}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create the Elasticsearch index of a searchable model (deprecated, use scout:index)';

    /**
     * Execute the console command.
     *
     * Creates the model's index with the engine's default analysis settings.
     * An existing index is left untouched, as "scout:index" does.
     *
     * @param  \Laravel\Scout\EngineManager  $manager
     * @return int
     */
    public function handle(EngineManager $manager): int
    {
        $this->warn('The scout:elasticsearch:create command is deprecated and will be removed in 2.0. Use "php artisan scout:index" instead.');

        $class = (string) $this->argument('model');

        if (! class_exists($class)) {
            $this->error("Model [{$class}] not found.");

            return self::FAILURE;
        }

        if (! is_subclass_of($class, Model::class) || ! in_array(Searchable::class, class_uses_recursive($class), true)) {
            $this->error("Model [{$class}] is not a searchable Eloquent model.");

            return self::FAILURE;
        }

        $name = (new $class)->indexableAs();

        try {
            $manager->engine('elasticsearch')->createIndex($name);
        } catch (Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Index [{$name}] is ready.");

        return self::SUCCESS;
    }
}
