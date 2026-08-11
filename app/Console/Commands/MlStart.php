<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class MlStart extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ml:start
                            {--background : Run the ML service in the background}
                            {--port=8001 : Port for the ML service to listen on}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the Python ML prediction microservice';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $mlServiceDir = base_path('ml_service');
        $port = $this->option('port');

        if (! is_dir($mlServiceDir)) {
            $this->error("ML service directory not found: {$mlServiceDir}");

            return self::FAILURE;
        }

        if (! file_exists($mlServiceDir.'/run_prod.py')) {
            $this->error("run_prod.py not found in {$mlServiceDir}");

            return self::FAILURE;
        }

        // Check if the ML service is already running
        if ($this->isServiceRunning($port)) {
            $this->info("ML service is already running on port {$port}.");

            return self::SUCCESS;
        }

        $this->info("Starting ML prediction service on port {$port}...");

        // Determine the Python command (python or python3)
        $pythonCmd = $this->findPython();

        if ($pythonCmd === null) {
            $this->error('Python is not installed or not in PATH. Please install Python 3.10+.');

            return self::FAILURE;
        }

        $command = sprintf(
            'cd /d "%s" && %s run_prod.py',
            $mlServiceDir,
            $pythonCmd
        );

        if ($this->option('background')) {
            // Windows: start in a new minimized window
            if (PHP_OS_FAMILY === 'Windows') {
                pclose(popen("start /MIN \"StockPrediction-ML\" cmd /c \"{$command}\"", 'r'));
            } else {
                exec("{$command} > /dev/null 2>&1 &");
            }

            $this->info('ML service started in background.');
            $this->info("Health check: http://localhost:{$port}/health");
            $this->newLine();
            $this->warn('Wait a few seconds for the service to initialize before making predictions.');
        } else {
            // Run in foreground (blocks the terminal)
            $this->info('Running in foreground. Press Ctrl+C to stop.');
            $this->info("API docs: http://localhost:{$port}/docs");
            $this->newLine();

            passthru($command);
        }

        return self::SUCCESS;
    }

    /**
     * Check if the ML service is already running on the given port.
     */
    private function isServiceRunning(string $port): bool
    {
        try {
            $response = Http::timeout(2)->get("http://localhost:{$port}/health");

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Find a usable Python executable.
     */
    private function findPython(): ?string
    {
        $candidates = ['python', 'python3', 'py'];

        foreach ($candidates as $cmd) {
            $output = [];
            $exitCode = 0;
            exec(sprintf('%s --version 2>&1', escapeshellcmd($cmd)), $output, $exitCode);

            if ($exitCode === 0 && count($output) > 0) {
                // Verify it's Python 3
                $version = implode(' ', $output);
                if (str_contains($version, 'Python 3')) {
                    return $cmd;
                }
            }
        }

        return null;
    }
}
