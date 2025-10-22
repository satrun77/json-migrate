<?php

namespace Silverstripe\JsonMigrate;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * MigrationTask.
 *
 * BuildTask that reads a YAML configuration and runs one or more JSON/JSONL
 * migrations using the JsonImporter. Intended to be executed via
 * dev/tasks or CLI (sake) in a SilverStripe project.
 */
class MigrationTask extends BuildTask
{
    protected static string $commandName = 'json-migrate';
    protected string $title = 'JSON Migration Task';
    protected static string $description = 'Run JSON/JSONL migrations from YAML config';

    /**
     * @return array<int,InputOption>
     */
    public function getOptions(): array
    {
        return [
            new InputOption(
                'config',
                null,
                InputOption::VALUE_OPTIONAL,
                'Path to YAML migration config file',
                BASE_PATH . '/app/_config/json-migrations.yml',
            ),
            new InputOption('debug', null, InputOption::VALUE_NONE, 'Enable debug output'),
        ];
    }

    public function execute(InputInterface $input, PolyOutput $output): int
    {
        $configPath = $input->getOption('config');
        $debug = (bool) $input->getOption('debug');

        if (!file_exists($configPath)) {
            $output->writeln("<error>❌ Config file not found: {$configPath}</error>");

            return Command::FAILURE;
        }

        $config = Yaml::parseFile($configPath);
        $migrations = $config['JsonMigrations'] ?? [];

        if (empty($migrations)) {
            $output->writeln("<comment>⚠️ No migrations defined in {$configPath}</comment>");

            return Command::SUCCESS;
        }

        foreach ($migrations as $index => $migration) {
            if (!$this->runSingleMigration($migration, $index, $debug, $output)) {
                return Command::FAILURE;
            }
        }

        $output->writeln("\n🎉 <info>All migrations finished successfully.</info>");

        return Command::SUCCESS;
    }

    /**
     * Factory method for creating an importer instance.
     *
     * This is separated out to make testing easier (mocking or subclassing).
     */
    protected function createImporter(MigrationConfig $config, bool $debug): JsonImporter
    {
        return new JsonImporter(
            $config->file,
            $config->class,
            $config->fieldMap,
            $config->folder,
            $config->batchSize,
            $debug,
            $config->stopOnError,
        );
    }

    /**
     * @param array<string,mixed> $migrationData
     */
    private function runSingleMigration(array $migrationData, int $index, bool $debug, PolyOutput $output): bool
    {
        $config = new MigrationConfig($migrationData);
        $className = $config->class ?? 'Unknown';

        try {
            if (!$config->isValid()) {
                $output->writeln("<error>❌ Migration #{$index} missing file, class, or fieldMap.</error>");

                return true;
            }

            $output->writeln("\n🚀 <info>Starting migration for {$config->class}</info>");
            $output->writeln("📂 File: {$config->file}");
            $output->writeln("🧾 Batch Size: {$config->batchSize} | Resume: " . ($config->resume ? 'Yes' : 'No'));

            $importer = $this->createImporter($config, $debug);

            $importer->process($config->resume);

            $output->writeln("✅ Completed migration for {$config->class}");
        } catch (Throwable $e) {
            $output->writeln("<error>💥 Error migrating {$className}: {$e->getMessage()}</error>");

            if ($config->stopOnError) {
                $output->writeln('<comment>⏹ Stopping due to stopOnError flag.</comment>');

                return false;
            }
        }

        return true;
    }
}
