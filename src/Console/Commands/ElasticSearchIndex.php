<?php

namespace Shabayek\Elastic\Console\Commands;

use Illuminate\Console\Command;

class ElasticSearchIndex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:elasticsearch:create {model}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create index for model';

    protected $client;

    public function __construct()
    {
        parent::__construct();

        $this->client = app('elasticsearch');
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (! class_exists($model = $this->argument('model'))) {
            $this->error("{$model} not found");
        }

        $model = new $model;

        try {
            $params = [
                'index' => $model->searchableAs(),
                'body' => [
                    'settings' => [
                        'index' => [
                            'analysis' => [
                                'analyzer' => $this->analyzers(),
                                'filter' => $this->filters(),
                            ],
                        ],
                    ],
                ],
            ];

            $this->client->indices()->create($params);
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }

    protected function filters()
    {
        return [
            'words_splitter' => [
                'type' => 'word_delimiter',
                'catenate_all' => true,
                'preserve_original' => false,
            ],
        ];
    }

    protected function analyzers()
    {
        return [
            'default' => [
                'type' => 'custom',
                'tokenizer' => 'standard',
                'char_filter' => ['html_strip'],
                'filter' => [
                    'lowercase',
                    'words_splitter',
                ],
            ],
        ];
    }
}
