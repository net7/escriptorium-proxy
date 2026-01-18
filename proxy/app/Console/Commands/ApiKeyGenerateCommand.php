<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

class ApiKeyGenerateCommand extends Command
{
    protected $signature = 'apikey:generate
                            {name? : Name of the API key}
                            {--permissions= : Comma-separated list of permissions (models,scripts,process)}
                            {--rate-limit=60 : Requests per minute}
                            {--expires= : Expiration date (Y-m-d format)}';

    protected $description = 'Generate a new API key';

    public function handle(): int
    {
        $name = $this->argument('name') ?? text(
            label: 'What is the name for this API key?',
            placeholder: 'My Application',
            required: true
        );

        $permissionsOption = $this->option('permissions');
        if ($permissionsOption) {
            $permissions = array_filter(array_map('trim', explode(',', $permissionsOption)));
        } else {
            $permissions = multiselect(
                label: 'Select permissions for this API key',
                options: [
                    'models' => 'Models - Access OCR models',
                    'scripts' => 'Scripts - Access scripts',
                    'process' => 'Process - Submit processing jobs',
                ],
                default: ['models', 'scripts', 'process'],
                hint: 'Leave empty for all permissions'
            );
        }

        $rateLimit = (int) $this->option('rate-limit');

        $expiresAt = null;
        if ($this->option('expires')) {
            $expiresAt = \Carbon\Carbon::parse($this->option('expires'))->endOfDay();
        }

        $result = ApiKey::generate($name, $permissions, $rateLimit, $expiresAt);

        $this->newLine();
        $this->components->info('API Key generated successfully!');
        $this->newLine();

        $this->components->twoColumnDetail('<fg=gray>ID</>', $result['model']->id);
        $this->components->twoColumnDetail('<fg=gray>Name</>', $result['model']->name);
        $this->components->twoColumnDetail('<fg=gray>Permissions</>', empty($permissions) ? 'All' : implode(', ', $permissions));
        $this->components->twoColumnDetail('<fg=gray>Rate Limit</>', $rateLimit.' req/min');
        $this->components->twoColumnDetail('<fg=gray>Expires</>', $expiresAt ? $expiresAt->toDateString() : 'Never');

        $this->newLine();
        $this->components->warn('Save this API key now. It will not be shown again!');
        $this->newLine();
        $this->line('  <fg=green;options=bold>'.$result['plain_key'].'</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
