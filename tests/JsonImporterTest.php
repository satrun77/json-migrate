<?php

namespace Silverstripe\JsonMigrate\Tests;

use ArrayIterator;
use PHPUnit\Framework\MockObject\Stub\ReturnCallback as ReturnCallbackStub;
use RuntimeException;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Dev\SapphireTest;
use Silverstripe\JsonMigrate\CheckpointManager;
use Silverstripe\JsonMigrate\JsonImporter;
use Silverstripe\JsonMigrate\JsonIteratorFactory;
use Silverstripe\JsonMigrate\Logger;
use Silverstripe\JsonMigrate\RecordProcessor;
use SilverStripe\Taxonomy\TaxonomyTerm;

/**
 * @internal
 * @phpcs:disable SlevomatCodingStandard.Functions.DisallowNamedArguments.DisallowedNamedArgument
 * @phpcs:disable SlevomatCodingStandard.Functions.FunctionLength.FunctionLength
 * @phpcs:disable SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingAnyTypeHint
 * @phpcs:disable SlevomatCodingStandard.Operators.DisallowIncrementAndDecrementOperators.DisallowedPreIncrementOperator
 * @phpcs:disable Generic.Files.LineLength.TooLong
 * @phpcs:disable SlevomatCodingStandard.Files.LineLength.LineTooLong
 * @phpcs:disable SlevomatCodingStandard.PHP.DisallowReference.DisallowedInheritingVariableByReference
 */
class JsonImporterTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        TestArticle::class,
    ];

    public function testScalarFieldImport(): void
    {
        $jsonPath = TEMP_PATH . '/test.json';
        $data = [
            ['title' => 'Test Article', 'publish_date' => '2025-10-22 12:34:00'],
        ];
        file_put_contents($jsonPath, json_encode($data));
        echo json_encode($data);
        $fieldMap = [
            'title' => ['type' => 'scalar', 'dest' => 'Title'],
            'publish_date' => ['type' => 'scalar', 'dest' => 'PublishDate', 'transform' => 'date'],
        ];

        $migrator = new JsonImporter($jsonPath, TestArticle::class, $fieldMap, 'TestImages');
        $migrator->process();

        $obj = TestArticle::get()->filter('Title', 'Test Article')->first();
        $this->assertNotNull($obj, 'Article should be created');
        $this->assertEquals('2025-10-22 12:34:00', $obj->PublishDate);
    }

    public function testTaxonomyLinking(): void
    {
        $jsonPath = TEMP_PATH . '/taxonomy.json';
        $data = [['title' => 'Tax Test', 'category' => 'News'], ['title' => 'Tax Test 2', 'category' => 'News']];
        file_put_contents($jsonPath, json_encode($data));

        $fieldMap = [
            'title' => ['type' => 'scalar', 'dest' => 'Title'],
            'category' => ['type' => 'taxonomy', 'dest' => 'Categories'],
        ];

        $migrator = new JsonImporter($jsonPath, TestArticle::class, $fieldMap, 'TestImages');
        $migrator->process();

        $obj = TestArticle::get()->filter('Title', 'Tax Test')->first();
        $this->assertEquals('News', $obj->Categories()->first()->Title);

        $obj = TestArticle::get()->filter('Title', 'Tax Test 2')->first();
        $this->assertEquals('News', $obj->Categories()->first()->Title);

        $this->assertCount(1, TaxonomyTerm::get()->filter('Name', 'News'), "Only one 'News' term should be created");
    }

    public function testLargeJsonlImportSimulation(): void
    {
        $stream = fopen('php://temp', 'r+');
        $numRecords = 1000;

        for ($i = 1; $i <= $numRecords; ++$i) {
            fwrite($stream, json_encode(['title' => 'Article ' . $i, 'publish_date' => '2025-10-22 12:00:00']) . "\n");
        }

        rewind($stream);

        $tempFile = TEMP_PATH . '/large_test.jsonl';
        file_put_contents($tempFile, stream_get_contents($stream));
        fclose($stream);

        $fieldMap = [
            'title' => ['type' => 'scalar', 'dest' => 'Title'],
            'publish_date' => ['type' => 'scalar', 'dest' => 'PublishDate'],
        ];

        $migrator = new JsonImporter($tempFile, TestArticle::class, $fieldMap, 'TestImages');
        $migrator->setBatchSize(100);
        $migrator->process();

        $this->assertEquals($numRecords, TestArticle::get()->count());
        TestArticle::get()->removeAll();

        unlink($tempFile);
    }

    public function testImageDownloadDeduplicationMocked(): void
    {
        $jsonPath = TEMP_PATH . '/images.json';
        $data = [
            [
                'title' => 'ImgTest',
                'images' => [
                    [
                        'src' => 'https://example.com/fake-image.jpg',
                        'title' => 'Pookeno Toilet Blocks Aug 2024',
                    ],
                ],
            ],
        ];
        file_put_contents($jsonPath, json_encode($data));

        $fieldMap = [
            'title' => ['type' => 'scalar', 'dest' => 'Title'],
            'images' => ['type' => 'images', 'dest' => 'Gallery'],
        ];

        $migrator = new JsonImporter($jsonPath, TestArticle::class, $fieldMap, 'TestImages');
        $recordProcessorMock = new class(TestArticle::class, $fieldMap, 'TestImages', true, new Logger(
            true,
        )) extends RecordProcessor {
            protected function downloadAndCreateImage(string $url, Folder $folder): ?Image
            {
                $pathInfo = pathinfo(parse_url($url, PHP_URL_PATH) ?: '');
                $ext = isset($pathInfo['extension'])
                    ? mb_strtolower($pathInfo['extension'])
                    : 'jpg';
                $filename = 'dummy-' . md5($url) . '.' . $ext;
                $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;

                file_put_contents($tmpFile, random_bytes(1024));

                $image = Image::create();
                $image->setFromLocalFile($tmpFile, $filename);
                $image->ParentID = $folder->ID;
                $image->Title = $pathInfo['filename'] ?? $filename;
                $image->write();

                @unlink($tmpFile);

                return $image;
            }
        };
        $migrator->setRecordProcessor($recordProcessorMock);
        $migrator->process();

        $obj = TestArticle::get()->filter('Title', 'ImgTest')->first();
        $this->assertNotNull($obj->Gallery()->first());
        $this->assertEquals(1, $obj->Gallery()->count());
    }

    public function testProcess(): void
    {
        $importer = new JsonImporter('test.json', TestArticle::class, ['Title' => ['dest' => 'Title']], 'images');

        $iteratorFactoryMock = $this->createMock(JsonIteratorFactory::class);
        $iteratorFactoryMock->method('create')->willReturn(new ArrayIterator([
            ['Title' => 'Article 1'], ['Title' => 'Article 2'], ['Title' => 'Article 3'],
        ]));

        $checkpointManagerMock = $this->createMock(CheckpointManager::class);
        $checkpointManagerMock->method('getCheckpointFile')->willReturn('/tmp/checkpoint.json');
        $checkpointManagerMock->method('getStartIndex')->willReturn(0);
        $checkpointManagerMock->expects($this->once())->method('delete');

        $importer->setIteratorFactory($iteratorFactoryMock);
        $importer->setCheckpointManager($checkpointManagerMock);

        $importer->process();
        $this->assertEquals(3, TestArticle::get()->count());
    }

    public function testProcessWithBatching(): void
    {
        $importer = new JsonImporter('test.json', TestArticle::class, ['Title' => ['dest' => 'Title']], 'images');
        $importer->setBatchSize(2);

        $iteratorFactoryMock = $this->createMock(JsonIteratorFactory::class);
        $iteratorFactoryMock->method('create')->willReturn(new ArrayIterator([
            ['Title' => 'Article 1'], ['Title' => 'Article 2'], ['Title' => 'Article 3'], ['Title' => 'Article 4'], ['Title' => 'Article 5'],
        ]));

        $checkpointManagerMock = $this->createMock(CheckpointManager::class);
        $checkpointManagerMock->method('getCheckpointFile')->willReturn('/tmp/checkpoint.json');
        $checkpointManagerMock->method('getStartIndex')->willReturn(2, 4);
        // Batch 1: save at index 2. Batch 2: save at index 4.
        $checkpointManagerMock->expects($this->exactly(3))->method('save');

        $importer->setIteratorFactory($iteratorFactoryMock);
        $importer->setCheckpointManager($checkpointManagerMock);

        $importer->process();
        $this->assertEquals(5, TestArticle::get()->count());
    }

    public function testProcessWithJsonlFile(): void
    {
        $importer = new JsonImporter('test.jsonl', TestArticle::class, ['title' => ['dest' => 'Title']], 'images');

        $iteratorFactoryMock = $this->createMock(JsonIteratorFactory::class);
        $iteratorFactoryMock->expects($this->once())
            ->method('create')
            ->with('test.jsonl')
            ->willReturn(new ArrayIterator([
                ['title' => 'Article 1'],
                ['title' => 'Article 2'],
            ]));

        $importer->setIteratorFactory($iteratorFactoryMock);
        $importer->process();
        $this->assertEquals(2, TestArticle::get()->count());
    }

    public function testProcessWithValidationErrorAndStopOnError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Validation failed for record');

        $importer = new JsonImporter(
            'test.json',
            TestArticle::class,
            ['Title' => ['dest' => 'Title']],
            'images',
            100,
            true,
            true, // stopOnError
        );

        $iteratorFactoryMock = $this->createMock(JsonIteratorFactory::class);
        $iteratorFactoryMock->method('create')->willReturn(new ArrayIterator([
            ['some_other_field' => 'foo'], // Invalid record, missing Title
        ]));
        $importer->setIteratorFactory($iteratorFactoryMock);

        $importer->process();
    }

    public function testProcessWithValidationErrorAndContinue(): void
    {
        $loggerMock = $this->createMock(Logger::class);
        $loggedMessages = [];
        $loggerMock->method('log')->will(
            new ReturnCallbackStub(static function (string $message) use (&$loggedMessages): void {
                $loggedMessages[] = $message;
            }),
        );

        $importer = new JsonImporter(
            'test.json',
            TestArticle::class,
            ['Title' => ['dest' => 'Title']],
            'images',
            100,
            true,
            false, // stopOnError = false
        );
        $importer->setLogger($loggerMock);

        $iteratorFactoryMock = $this->createMock(JsonIteratorFactory::class);
        $iteratorFactoryMock->method('create')->willReturn(new ArrayIterator([
            ['Title' => 'Valid Article'],
            ['id' => 2], // Invalid record
            ['Title' => 'Another Valid Article'],
        ]));
        $importer->setIteratorFactory($iteratorFactoryMock);

        $importer->process();

        $this->assertEquals(2, TestArticle::get()->count());
        $this->assertStringContainsString('Validation failed for record', implode('\n', $loggedMessages));
        $this->assertStringContainsString('Title must not be empty', implode('\n', $loggedMessages));
    }
}
