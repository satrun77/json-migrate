<?php

namespace Silverstripe\JsonMigrate;

use RuntimeException;

/**
 * Manages checkpoints for resumable migrations.
 *
 * This class handles the creation, retrieval, and deletion of checkpoint files,
 * which store the progress of a migration, allowing it to be resumed later.
 */
class CheckpointManager
{
    /**
     * @var string The directory where checkpoint files are stored.
     */
    protected string $checkpointDir;

    /**
     * Constructor.
     *
     * Initializes the checkpoint manager, ensuring the checkpoint directory exists.
     *
     * @param string $checkpointDir Optional. The path to the checkpoint directory.
     *                              If not provided, a default directory in TEMP_PATH is used.
     */
    public function __construct(string $checkpointDir = '')
    {
        $this->checkpointDir = $checkpointDir ?: TEMP_PATH . '/json-import-checkpoints';

        // Create the checkpoint directory if it doesn't exist.
        if (!is_dir($this->checkpointDir)
            && !mkdir($this->checkpointDir, 0775, true)
            && !is_dir($this->checkpointDir)
        ) {
            throw new RuntimeException("Failed to create checkpoint directory: {$this->checkpointDir}");
        }
    }

    /**
     * Generates a unique checkpoint file path based on a given key.
     *
     * @param string $key A unique identifier for the migration (e.g., the source file path).
     * @return string The absolute path to the checkpoint file.
     */
    public function getCheckpointFile(string $key): string
    {
        return $this->checkpointDir . '/' . md5($key) . '.json';
    }

    /**
     * Reads a checkpoint file and returns the index from which to resume processing.
     *
     * @param string $checkpointFile The path to the checkpoint file.
     * @return int The index of the next record to process. Returns 0 if no checkpoint exists.
     */
    public function getStartIndex(string $checkpointFile): int
    {
        if (!file_exists($checkpointFile)) {
            return 0;
        }

        $content = file_get_contents($checkpointFile);

        if ($content === false) {
            return 0;
        }

        $data = json_decode($content, true);
        // The last saved index was the last successfully processed record, so start at the next one.
        return ($data['last_index'] ?? -1) + 1;
    }

    /**
     * Saves the migration progress to a checkpoint file.
     *
     * @param string $checkpointFile The path to the checkpoint file.
     * @param int $index The index of the last successfully processed record.
     */
    public function save(string $checkpointFile, int $index): void
    {
        file_put_contents($checkpointFile, json_encode(['last_index' => $index]));
    }

    /**
     * Deletes a checkpoint file.
     *
     * This is typically done after a migration has completed successfully.
     *
     * @param string $checkpointFile The path to the checkpoint file.
     */
    public function delete(string $checkpointFile): void
    {
        if (!file_exists($checkpointFile)) {
            return;
        }

        unlink($checkpointFile);
    }
}
