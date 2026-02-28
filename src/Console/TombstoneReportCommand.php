<?php

declare(strict_types=1);

namespace Wprigollopes\LaravelTombstone\Console;

use Illuminate\Console\Command;
use Scheb\Tombstone\Analyzer\Cli\ConsoleOutput;
use Scheb\Tombstone\Analyzer\Log\AnalyzerLogProvider;
use Scheb\Tombstone\Analyzer\Log\LogCollector;
use Scheb\Tombstone\Analyzer\Matching\MethodNameStrategy;
use Scheb\Tombstone\Analyzer\Matching\PositionStrategy;
use Scheb\Tombstone\Analyzer\Matching\Processor;
use Scheb\Tombstone\Analyzer\Matching\VampireMatcher;
use Scheb\Tombstone\Analyzer\Model\TombstoneIndex;
use Scheb\Tombstone\Analyzer\Model\VampireIndex;
use Scheb\Tombstone\Analyzer\Report\Console\ConsoleReportGenerator;
use Scheb\Tombstone\Analyzer\Report\Html\HtmlReportGenerator;
use Scheb\Tombstone\Analyzer\Report\ReportExporter;
use Scheb\Tombstone\Analyzer\Stock\ParserTombstoneProvider;
use Scheb\Tombstone\Analyzer\Stock\TombstoneCollector;

class TombstoneReportCommand extends Command
{
    protected $signature = 'tombstone:report
        {--html : Generate an HTML report}
        {--html-output= : Directory for the HTML report}';

    protected $description = 'Analyze tombstones and generate a dead code report';

    public function handle(): int
    {
        $config = $this->buildAnalyzerConfig();
        $consoleOutput = new ConsoleOutput($this->getOutput());

        $this->info('Collecting tombstones from source code...');
        $tombstoneIndex = new TombstoneIndex();
        try {
            $tombstoneProvider = ParserTombstoneProvider::create($config, $consoleOutput);
            $tombstoneCollector = new TombstoneCollector([$tombstoneProvider], $tombstoneIndex);
            $tombstoneCollector->collectTombstones();
        } catch (\Throwable $e) {
            $this->warn('Error scanning source code: ' . $e->getMessage());
            $this->warn('Some files could not be parsed. Report may be incomplete.');
        }

        $this->info('Collecting vampire logs...');
        $vampireIndex = new VampireIndex();
        $logDir = $config['logs']['directory'];
        if (is_dir($logDir)) {
            $logProvider = AnalyzerLogProvider::create($config, $consoleOutput);
            $logCollector = new LogCollector([$logProvider], $vampireIndex);
            $logCollector->collectLogs();
        }

        $this->info('Analyzing tombstones...');
        $processor = new Processor(new VampireMatcher([
            new MethodNameStrategy(),
            new PositionStrategy(),
        ]));
        $result = $processor->process($tombstoneIndex, $vampireIndex);

        $reportGenerators = [
            ConsoleReportGenerator::create($config, $consoleOutput),
        ];

        if ($this->option('html')) {
            $htmlOutput = $this->option('html-output')
                ?? config('tombstone.report.html_output')
                ?? storage_path('tombstone/report');

            $config['report']['html'] = $htmlOutput;

            if (! is_dir($htmlOutput)) {
                @mkdir($htmlOutput, 0755, true);
            }

            $reportGenerators[] = HtmlReportGenerator::create($config, $consoleOutput);
            $this->info("HTML report will be written to: {$htmlOutput}");
        }

        $exporter = new ReportExporter($consoleOutput, $reportGenerators);
        $exporter->generate($result);

        return self::SUCCESS;
    }

    private function buildAnalyzerConfig(): array
    {
        $tombstoneConfig = config('tombstone');

        return [
            'source_code' => [
                'root_directory' => $tombstoneConfig['root_directory'] ?? base_path(),
            ],
            'tombstones' => [
                'parser' => [
                    'excludes' => $tombstoneConfig['report']['source_excludes'] ?? ['vendor', 'node_modules'],
                    'names' => ['*.php'],
                    'not_names' => [],
                    'function_names' => ['tombstone'],
                ],
            ],
            'logs' => [
                'directory' => $tombstoneConfig['analyzer_log']['log_dir'] ?? storage_path('tombstone'),
            ],
            'report' => [
                'console' => true,
            ],
        ];
    }
}
