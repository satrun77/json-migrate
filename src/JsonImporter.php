<?php

namespace Silverstripe\JsonMigrate;

/**
 * JsonImporter
 *
 * Orchestrates the migration of data from JSON/JSONL files into SilverStripe DataObjects.
 * This class handles file iteration, batch processing, and delegates specific tasks
 * like checkpoint management, record processing, and logging to dedicated helper classes.
 */
class JsonImporter
{
    /**
     * @var string The absolute path to the JSON/JSONL file.
     */
    protected string $jsonPath;

    /**
     * @var int|null The number of records to process in each batch. If null, the entire file is processed in one go.
     */
    protected ?int $batchSize = null;

    /**
     * @var CheckpointManager Manages saving and retrieving progress checkpoints.
     */
    protected CheckpointManager $checkpointManager;

    /**
     * @var RecordProcessor Processes a single JSON record and maps it to a DataObject.
     */
    protected RecordProcessor $recordProcessor;

    /**
     * @var Logger Handles logging of import progress and errors.
     */
    protected Logger $logger;

    /**
     * @var JsonIteratorFactory Creates an iterator to read the JSON/JSONL file.
     */
    protected JsonIteratorFactory $iteratorFactory;

    /**
     * @param string $jsonPath The absolute path to the JSON/JSONL file.
     * @param string $dataObjectClass The FQCN of the DataObject to import into.
     * @param array $fieldMap The mapping of JSON fields to DataObject properties.
     * @param string $targetFolder The folder to store imported images in.
     * @param int|null $batchSize The number of records to process in each batch.
     * @param bool $debug If true, enables detailed logging.
     * @param bool $stopOnError If true, the import will stop on the first error.
     */
    public function __construct(
        string $jsonPath,
        string $dataObjectClass,
        array  $fieldMap,
        string $targetFolder,
        ?int   $batchSize = 100,
        bool   $debug = false,
        bool   $stopOnError = true
    ) {
        $this->jsonPath = $jsonPath;
        $this->batchSize = $batchSize;

        $this->logger = new Logger($debug);
        $this->checkpointManager = new CheckpointManager();
        $this->iteratorFactory = new JsonIteratorFactory($debug);
        $this->recordProcessor = new RecordProcessor(
            $dataObjectClass,
            $fieldMap,
            $targetFolder,
            $stopOnError,
            $this->logger,
        );
    }

    /**
     * Starts or resumes the import process.
     *
     * @param bool $resume If true, the import will attempt to resume from the last checkpoint.
     */
    public function process(bool $resume = false): void
    {
        $checkpointFile = $this->checkpointManager->getCheckpointFile($this->jsonPath);

        do {
            $processedCount = $this->processBatch($checkpointFile, $resume);
            $resume = true; // Always resume after the first batch
        } while ($this->batchSize && $processedCount >= $this->batchSize);

        $this->logger->log("Finished import.");
        $this->checkpointManager->delete($checkpointFile);
        $this->logger->log('Deleted checkpoint file');
    }

    /**
     * Processes a single batch of records from the JSON file.
     *
     * @param string $checkpointFile The path to the checkpoint file.
     * @param bool $resume If true, the batch will start from the last saved index.
     * @return int The number of records processed in this batch.
     */
    protected function processBatch(string $checkpointFile, bool $resume): int
    {
        $startIndex = $resume ? $this->checkpointManager->getStartIndex($checkpointFile) : 0;

        if ($startIndex > 0) {
            $this->logger->log("Resuming from checkpoint: startIndex={$startIndex}");
        } else {
            $this->logger->log("Starting new batch. Resume={$resume}, startIndex=0");
        }

        $iterator = $this->iteratorFactory->create($this->jsonPath);
        $index = 0;
        $processedInBatch = 0;

        foreach ($iterator as $item) {
            // Skip records until the start index is reached
            if ($index < $startIndex) {
                $index++;

                continue;
            }

            $this->logger->log("Processing record #{$index}: " . json_encode($item));
            $this->recordProcessor->process($item);

            $processedInBatch++;
            $index++;

            // If batch size is reached, stop processing for this batch
            if ($this->batchSize && ($processedInBatch >= $this->batchSize)) {
                $this->logger->log("Batch size {$this->batchSize} reached at record #{$index}, stopping early...");

                break;
            }
        }

        // Save checkpoint if we processed any records in this batch
        if ($this->batchSize && $processedInBatch > 0) {
            $this->checkpointManager->save($checkpointFile, $index - 1);
        }

        return $processedInBatch;
    }

    /**
     * @param int $size
     * @return static
     */
    public function setBatchSize(int $size): static
    {
        $this->batchSize = $size;

        return $this;
    }

    /**
     * @param Logger $logger
     * @return static
     */
    public function setLogger(Logger $logger): static
    {
        $this->logger = $logger;
        $this->getRecordProcessor()->setLogger($logger);

        return $this;
    }

    /**
     * @param CheckpointManager $checkpointManager
     * @return static
     */
    public function setCheckpointManager(CheckpointManager $checkpointManager): static
    {
        $this->checkpointManager = $checkpointManager;

        return $this;
    }

    /**
     * @param JsonIteratorFactory $iteratorFactory
     * @return static
     */
    public function setIteratorFactory(JsonIteratorFactory $iteratorFactory): static
    {
        $this->iteratorFactory = $iteratorFactory;

        return $this;
    }

    /**
     * @param RecordProcessor $recordProcessor
     * @return static
     */
    public function setRecordProcessor(RecordProcessor $recordProcessor): static
    {
        $this->recordProcessor = $recordProcessor;

        return $this;
    }

    /**
     * @return RecordProcessor
     */
    public function getRecordProcessor(): RecordProcessor
    {
        return $this->recordProcessor;
    }
}
