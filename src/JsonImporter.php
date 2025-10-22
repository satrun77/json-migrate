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
    protected string $jsonPath;
    protected ?int $batchSize = null;
    protected CheckpointManager $checkpointManager;
    protected RecordProcessor $recordProcessor;
    protected Logger $logger;
    protected JsonIteratorFactory $iteratorFactory;

    public function __construct(
        string $jsonPath,
        string $dataObjectClass,
        array $fieldMap,
        string $targetFolder,
        ?int $batchSize = 100,
        bool $debug = false,
        bool $stopOnError = true
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
            $this->logger
        );
    }

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
            if ($index < $startIndex) {
                $index++;
                continue;
            }

            $this->logger->log("Processing record #{$index}: " . json_encode($item));
            $this->recordProcessor->process($item);

            $processedInBatch++;
            $index++;

            if ($this->batchSize && ($processedInBatch >= $this->batchSize)) {
                $this->logger->log("Batch size {$this->batchSize} reached at record #{$index}, stopping early...");
                break;
            }
        }

        if ($this->batchSize && $processedInBatch > 0) {
            $this->checkpointManager->save($checkpointFile, $index - 1);
        }

        return $processedInBatch;
    }

    public function setBatchSize(int $size): static
    {
        $this->batchSize = $size;
        return $this;
    }

    public function setLogger(Logger $logger): static
    {
        $this->logger = $logger;
        $this->getRecordProcessor()->setLogger($logger);
        return $this;
    }

    public function setCheckpointManager(CheckpointManager $checkpointManager): static
    {
        $this->checkpointManager = $checkpointManager;
        return $this;
    }

    public function setIteratorFactory(JsonIteratorFactory $iteratorFactory): static
    {
        $this->iteratorFactory = $iteratorFactory;
        return $this;
    }

    public function setRecordProcessor(RecordProcessor $recordProcessor): static
    {
        $this->recordProcessor = $recordProcessor;
        return $this;
    }

    public function getRecordProcessor(): RecordProcessor
    {
        return $this->recordProcessor;
    }
}
