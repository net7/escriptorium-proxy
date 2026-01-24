<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PostProcessOpenApi extends Command
{
    protected $signature = 'openapi:export';

    protected $description = 'Export and format OpenAPI spec';

    public function handle(): int
    {
        $this->info('Running scramble:export...');
        $this->call('scramble:export');

        $path = base_path('api.json');

        if (! File::exists($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $spec = json_decode(File::get($path), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON file');

            return self::FAILURE;
        }

        // Format with pretty print
        File::put($path, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->info("OpenAPI spec exported: {$path}");

        return self::SUCCESS;
    }
}
