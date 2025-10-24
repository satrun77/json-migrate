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
    /**
     * @var string The name of the command to be used in the CLI.
     */
    protected static string $commandName = 'json-migrate';

    /**
     * @var string The title of the BuildTask.
     */
    protected string $title = 'JSON Migration Task';

    /**
     * @var string The description of the BuildTask.
     */
    protected static string $description = 'Run JSON/JSONL migrations from YAML config';

    /**
     * Defines the command-line options for the task.
     *
     * @return array<int,InputOption> An array of InputOption objects.
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

    /**
     * Executes the migration task.
     *
     * @param InputInterface $input The command-line input.
     * @param PolyOutput $output The output to write messages to.
     * @return int The exit code of the command.
     */
    public function execute(InputInterface $input, PolyOutput $output): int
    {
        $configPath = $input->getOption('config');
        $debug = (bool)$input->getOption('debug');

        if (!file_exists($configPath)) {
            $output->writeln("<error>❌ Config file not found: {$configPath}</error>");

            return Command::FAILURE;
        }

        $config = Yaml::parseFile($configPath);
        $migrations = $config['JsonMigrations'] ?? [];

        if (!count($migrations)) {
            $output->writeln("<comment>⚠️ No migrations defined in {$configPath}</comment>");

            return Command::SUCCESS;
        }

        foreach ($migrations as $index => $migration) {
            // If a single migration fails and stopOnError is true, this will return false.
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
     *
     * @param MigrationConfig $config The migration configuration object.
     * @param bool $debug Whether to enable debug mode on the importer.
     * @return JsonImporter An instance of the JsonImporter.
     */
    protected function createImporter(MigrationConfig $config, bool $debug): JsonImporter
    {
        return new JsonImporter(
            $config->getFile(),
            $config->getClass(),
            $config->getFieldMap(),
            $config->getFolder(),
            $config->getBatchSize(),
            $debug,
            $config->getStopOnError(),
        );
    }

    /**
     * Runs a single migration based on the provided configuration.
     *
     * @param array<string,mixed> $migrationData The configuration for a single migration.
     * @param int $index The index of the migration in the config file.
     * @param bool $debug Whether to enable debug output.
     * @param PolyOutput $output The output to write messages to.
     * @return bool True on success or non-blocking failure, false on blocking failure.
     */
    private function runSingleMigration(array $migrationData, int $index, bool $debug, PolyOutput $output): bool
    {
        $config = new MigrationConfig($migrationData);
        $className = $config->getClass() ?? 'Unknown';

        try {
            if (!$config->isValid()) {
                $output->writeln("<error>❌ Migration #{$index} missing file, class, or fieldMap.</error>");

                return true; // Continue to next migration
            }

            $output->writeln("\n🚀 <info>Starting migration for {$config->getClass()}</info>");
            $output->writeln("📂 File: {$config->getFile()}");
            $output->writeln(
                "🧾 Batch Size: {$config->getBatchSize()} | Resume: " . ($config->getResume() ? 'Yes' : 'No'),
            );

            $importer = $this->createImporter($config, $debug);
            $importer->process($config->getResume());

            $output->writeln("✅ Completed migration for {$config->getClass()}");
        } catch (Throwable $e) {
            $output->writeln("<error>💥 Error migrating {$className}: {$e->getMessage()}</error>");

            if ($config->getStopOnError()) {
                $output->writeln('<comment>⏹ Stopping due to stopOnError flag.</comment>');

                return false; // Stop all migrations
            }
        }

        return true; // Continue to next migration
    }
}
