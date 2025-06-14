<?php

namespace Jayesh\LaravelGeminiTranslator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class InstallCommand extends Command
{
    protected $signature = 'translations:install';

    protected $description = 'Installs the necessary assets for the Laravel Gemini Translator package.';

    public function handle(): void
    {
        $this->comment('Publishing Gemini configuration...');

        // This runs: php artisan vendor:publish --provider="Gemini\Laravel\GeminiServiceProvider" --force
        Artisan::call('vendor:publish', [
            '--provider' => 'Gemini\Laravel\GeminiServiceProvider',
            '--force' => true,
        ]);

        $this->info('Gemini configuration published successfully.');
        $this->output->newLine();

        $this->comment('------------------------------------------------------------------');
        $this->comment(' Laravel Gemini Translator - Post-Install Setup');
        $this->comment('------------------------------------------------------------------');
        $this->info('✅ Please add the following variables to your .env file:');
        $this->output->newLine();
        $this->line('<fg=green>GEMINI_API_KEY</>=YOUR_ACTUAL_API_KEY');
        $this->line('<fg=green>GEMINI_REQUEST_TIMEOUT</>=600');
        $this->output->newLine();
        $this->info('Get your API key from Google AI Studio.');
        $this->comment('------------------------------------------------------------------');
    }
}