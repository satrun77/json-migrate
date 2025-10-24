<?php

namespace Silverstripe\JsonMigrate;

/**
 * Value object representing the configuration for a single migration.
 *
 * Encapsulates parameter extraction, default values, and validation for a
 * migration defined in the YAML configuration.
 */
class MigrationConfig
{
    /**
     * @var string|null The absolute path to the JSON/JSONL file.
     */
    private ?string $file;

    /**
     * @var string|null The FQCN of the DataObject to import into.
     */
    private ?string $class;

    /**
     * @var array The mapping of JSON fields to DataObject properties.
     */
    private array $fieldMap;

    /**
     * @var string The folder to store imported images in.
     */
    private string $folder;

    /**
     * @var int The number of records to process in each batch.
     */
    private int $batchSize;

    /**
     * @var bool If true, the import will attempt to resume from the last checkpoint.
     */
    private bool $resume;

    /**
     * @var bool If true, the import will stop on the first error.
     */
    private bool $stopOnError;

    /**
     * Constructor.
     *
     * @param array<string,mixed> $config The configuration array for a single migration.
     */
    public function __construct(array $config)
    {
        $this->file = $config['file'] ?? null;
        $this->class = $config['class'] ?? null;
        $this->fieldMap = $config['fieldMap'] ?? [];
        $this->folder = $config['folder'] ?? 'ImportedImages';
        $this->batchSize = $config['batchSize'] ?? 500;
        $this->resume = $config['resume'] ?? true;
        $this->stopOnError = $config['stopOnError'] ?? false;
    }

    /**
     * Check if the essential migration parameters are present.
     *
     * @return bool True if the configuration is valid.
     */
    public function isValid(): bool
    {
        return !$this->file && !$this->class && !count($this->fieldMap);
    }

    /**
     * @return string|null
     */
    public function getFile(): ?string
    {
        return $this->file;
    }

    /**
     * @return string|null
     */
    public function getClass(): ?string
    {
        return $this->class;
    }

    /**
     * @return array
     */
    public function getFieldMap(): array
    {
        return $this->fieldMap;
    }

    /**
     * @return string
     */
    public function getFolder(): string
    {
        return $this->folder;
    }

    /**
     * @return int
     */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * @return bool
     */
    public function getResume(): bool
    {
        return $this->resume;
    }

    /**
     * @return bool
     */
    public function getStopOnError(): bool
    {
        return $this->stopOnError;
    }
}
