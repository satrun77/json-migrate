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
    public ?string $file;
    public ?string $class;
    public array $fieldMap;
    public string $folder;
    public int $batchSize;
    public bool $resume;
    public bool $stopOnError;

    /**
     * @param array<string,mixed> $config
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
     */
    public function isValid(): bool
    {
        return !empty($this->file) && !empty($this->class) && !empty($this->fieldMap);
    }
}
