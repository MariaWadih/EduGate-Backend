<?php

namespace App\Console\Commands;

use App\Services\InsightsService;
use Illuminate\Console\Command;

class GenerateInsights extends Command
{
    protected $signature = 'app:generate-insights';
    protected $description = 'Trigger the Insights Engine to process school data';

    public function handle(InsightsService $insightsService)
    {
        $this->info('Starting Insights Engine...');
        $insightsService->generateDailyInsights();
        $this->info('Insights generated successfully.');
    }
}
