<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\search;

class ApiKeyRevokeCommand extends Command
{
    protected $signature = 'apikey:revoke
                            {id? : The ID or prefix of the API key to revoke}
                            {--force : Skip confirmation}';

    protected $description = 'Revoke an API key';

    public function handle(): int
    {
        $id = $this->argument('id');

        if (! $id) {
            $id = search(
                label: 'Search for an API key to revoke',
                options: fn (string $value) => ApiKey::query()
                    ->active()
                    ->where(fn ($q) => $q
                        ->where('name', 'like', "%{$value}%")
                        ->orWhere('key_prefix', 'like', "%{$value}%")
                        ->orWhere('id', 'like', "%{$value}%")
                    )
                    ->limit(10)
                    ->get()
                    ->mapWithKeys(fn (ApiKey $key) => [$key->id => "{$key->name} ({$key->key_prefix}...)"])
                    ->toArray(),
                placeholder: 'Type to search by name, prefix, or ID...'
            );
        }

        // Try to find by ID first, then by prefix
        $apiKey = ApiKey::find($id) ?? ApiKey::where('key_prefix', 'like', $id.'%')->first();

        if (! $apiKey) {
            $this->components->error("API key not found: {$id}");

            return self::FAILURE;
        }

        if (! $apiKey->is_active) {
            $this->components->warn("API key '{$apiKey->name}' is already revoked.");

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=gray>ID</>', $apiKey->id);
        $this->components->twoColumnDetail('<fg=gray>Name</>', $apiKey->name);
        $this->components->twoColumnDetail('<fg=gray>Prefix</>', $apiKey->key_prefix);
        $this->components->twoColumnDetail('<fg=gray>Created</>', $apiKey->created_at->toDateTimeString());
        $this->components->twoColumnDetail('<fg=gray>Last Used</>', $apiKey->last_used_at?->toDateTimeString() ?? 'Never');
        $this->newLine();

        if (! $this->option('force')) {
            $confirmed = confirm(
                label: 'Are you sure you want to revoke this API key?',
                default: false
            );

            if (! $confirmed) {
                $this->components->info('Operation cancelled.');

                return self::SUCCESS;
            }
        }

        $apiKey->revoke();

        $this->components->info("API key '{$apiKey->name}' has been revoked.");

        return self::SUCCESS;
    }
}
