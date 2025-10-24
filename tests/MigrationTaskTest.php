<?php

namespace Silverstripe\JsonMigrate\Tests;

use Exception;
use SilverStripe\Dev\SapphireTest;
use Silverstripe\JsonMigrate\JsonImporter;
use Silverstripe\JsonMigrate\MigrationConfig;
use Silverstripe\JsonMigrate\MigrationTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Yaml\Yaml;

/**
 * @internal
 * @phpcs:disable SlevomatCodingStandard.Functions.DisallowNamedArguments.DisallowedNamedArgument
 * @phpcs:disable SlevomatCodingStandard.Functions.FunctionLength.FunctionLength
 */
class MigrationTaskTest extends SapphireTest
{
    protected BufferedOutput $buffer;
    protected PolyOutput $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buffer = new BufferedOutput();
        $this->output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $this->buffer);
    }

    public function testGetOptions(): void
    {
        $task = new MigrationTask();
        $options = $task->getOptions();

        $this->assertCount(2, $options);

        $this->assertInstanceOf(InputOption::class, $options[0]);
        $this->assertEquals('config', $options[0]->getName());
        $this->assertEquals(BASE_PATH . '/app/_config/json-migrations.yml', $options[0]->getDefault());

        $this->assertInstanceOf(InputOption::class, $options[1]);
        $this->assertEquals('debug', $options[1]->getName());
    }

    public function testExecuteConfigNotFound(): void
    {
        $task = new MigrationTask();
        $input = $this->createInput($task, ['--config' => '/path/to/non-existent.yml']);

        $result = $task->execute($input, $this->output);
        $outputText = $this->buffer->fetch();

        $this->assertEquals(Command::FAILURE, $result);
        $this->assertStringContainsString('Config file not found: /path/to/non-existent.yml', $outputText);
    }

    public function testExecuteNoMigrations(): void
    {
        $configFile = $this->createTempConfigFile(['JsonMigrations' => []]);

        $task = new MigrationTask();
        $input = $this->createInput($task, ['--config' => $configFile]);

        $result = $task->execute($input, $this->output);
        $outputText = $this->buffer->fetch();

        $this->assertEquals(Command::SUCCESS, $result);
        $this->assertStringContainsString("No migrations defined in {$configFile}", $outputText);

        unlink($configFile);
    }

    public function testExecuteSuccess(): void
    {
        $configFile = $this->createTempConfigFile([
            'JsonMigrations' => [
                [
                    'file' => 'test.json',
                    'class' => TestArticle::class,
                    'fieldMap' => ['ID' => 'MyID'],
                ],
            ],
        ]);

        $importerMock = $this->createMock(JsonImporter::class);
        $importerMock->expects($this->once())->method('process')->with(true);

        $task = $this->getMockBuilder(MigrationTask::class)
            ->onlyMethods(['createImporter'])
            ->getMock();

        $task->method('createImporter')
            ->with($this->callback(function (MigrationConfig $config) {
                $this->assertEquals('test.json', $config->getFile());
                $this->assertEquals(TestArticle::class, $config->getClass());
                $this->assertEquals(['ID' => 'MyID'], $config->getFieldMap());
                $this->assertEquals(false, $config->getStopOnError());

                return true;
            }), $this->equalTo(false))
            ->willReturn($importerMock);

        $input = $this->createInput($task, ['--config' => $configFile]);

        $result = $task->execute($input, $this->output);
        $outputText = $this->buffer->fetch();

        $this->assertEquals(Command::SUCCESS, $result);
        $this->assertStringContainsString('Starting migration for ' . TestArticle::class, $outputText);
        $this->assertStringContainsString('File: test.json', $outputText);
        $this->assertStringContainsString('Batch Size: 500 | Resume: Yes', $outputText);
        $this->assertStringContainsString('Completed migration for ' . TestArticle::class, $outputText);
        $this->assertStringContainsString('All migrations finished successfully.', $outputText);

        unlink($configFile);
    }

    public function testExecuteMissingParams(): void
    {
        $configFile = $this->createTempConfigFile([
            'JsonMigrations' => [
                ['file' => 'test.json'],
            ],
        ]);

        $task = new MigrationTask();
        $input = $this->createInput($task, ['--config' => $configFile]);

        $result = $task->execute($input, $this->output);
        $outputText = $this->buffer->fetch();

        $this->assertEquals(Command::SUCCESS, $result);
        $this->assertStringContainsString('Migration #0 missing file, class, or fieldMap.', $outputText);

        unlink($configFile);
    }

    public function testExecuteWithErrorNoStop(): void
    {
        $configFile = $this->createTempConfigFile([
            'JsonMigrations' => [
                [
                    'file' => 'test.json',
                    'class' => TestArticle::class,
                    'fieldMap' => ['ID' => 'MyID'],
                ],
            ],
        ]);

        $importerMock = $this->createMock(JsonImporter::class);
        $importerMock->method('process')->will($this->throwException(new Exception('Something went wrong')));

        $task = $this->getMockBuilder(MigrationTask::class)
            ->onlyMethods(['createImporter'])
            ->getMock();

        $task->method('createImporter')
            ->with($this->callback(function (MigrationConfig $config) {
                $this->assertEquals(false, $config->getStopOnError());

                return true;
            }), $this->equalTo(false))
            ->willReturn($importerMock);

        $input = $this->createInput($task, ['--config' => $configFile]);

        $result = $task->execute($input, $this->output);
        $outputText = $this->buffer->fetch();

        $this->assertEquals(Command::SUCCESS, $result);
        $this->assertStringContainsString(
            'Error migrating ' . TestArticle::class . ': Something went wrong',
            $outputText,
        );
        $this->assertStringContainsString('All migrations finished successfully', $outputText);

        unlink($configFile);
    }

    public function testExecuteWithErrorAndStop(): void
    {
        $configFile = $this->createTempConfigFile([
            'JsonMigrations' => [
                [
                    'file' => 'test.json',
                    'class' => TestArticle::class,
                    'fieldMap' => ['ID' => 'MyID'],
                    'stopOnError' => true,
                ],
            ],
        ]);

        $importerMock = $this->createMock(JsonImporter::class);
        $importerMock->method('process')->will($this->throwException(new Exception('Something went wrong')));

        $task = $this->getMockBuilder(MigrationTask::class)
            ->onlyMethods(['createImporter'])
            ->getMock();

        $task->method('createImporter')
            ->with($this->callback(function (MigrationConfig $config) {
                $this->assertEquals(true, $config->getStopOnError());

                return true;
            }), $this->equalTo(false))
            ->willReturn($importerMock);

        $input = $this->createInput($task, ['--config' => $configFile]);

        $result = $task->execute($input, $this->output);
        $outputText = $this->buffer->fetch();

        $this->assertEquals(Command::FAILURE, $result);
        $this->assertStringContainsString(
            'Error migrating ' . TestArticle::class . ': Something went wrong',
            $outputText,
        );
        $this->assertStringContainsString('Stopping due to stopOnError flag', $outputText);

        unlink($configFile);
    }

    private function createInput(MigrationTask $task, array $params): ArrayInput
    {
        $definition = new InputDefinition($task->getOptions());
        $input = new ArrayInput($params, $definition);
        $input->setInteractive(false);

        return $input;
    }

    private function createTempConfigFile(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'yml');
        file_put_contents($path, Yaml::dump($data));

        return $path;
    }
}
