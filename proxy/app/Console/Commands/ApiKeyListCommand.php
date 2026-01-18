<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

class ApiKeyListCommand extends Command
{
    protected $signature = 'apikey:list
                            {--all : Show all keys including inactive ones}';

    protected $description = 'List all API keys';

    public function handle(): int
    {
        $query = ApiKey::query()->orderBy('created_at', 'desc');

        if (! $this->option('all')) {
            $query->valid();
        }

        $keys = $query->get();

        if ($keys->isEmpty()) {
            $this->components->info('No API keys found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Prefix', 'Permissions', 'Rate Limit', 'Last Used', 'Expires', 'Status'],
            $keys->map(fn (ApiKey $key) => [
                $key->id,
                $key->name,
                $key->key_prefix.'...',
                empty($key->permissions) ? 'All' : implode(', ', $key->permissions),
                $key->rate_limit.'/min',
                $key->last_used_at?->diffForHumans() ?? 'Never',
                $key->expires_at?->toDateString() ?? 'Never',
                $this->getStatusBadge($key),
            ])
        );

        return self::SUCCESS;
    }

    protected function getStatusBadge(ApiKey $key): string
    {
        if (! $key->is_active) {
            return '<fg=red>Revoked</>';
        }

        if ($key->expires_at && $key->expires_at->isPast()) {
            return '<fg=yellow>Expired</>';
        }

        return '<fg=green>Active</>';
    }
}
