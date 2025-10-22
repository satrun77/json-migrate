<?php

namespace Silverstripe\JsonMigrate;

use Generator;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use RuntimeException;

/**
 * Factory for creating iterators for JSON and JSONL files.
 *
 * This class determines the correct iterator to use based on the file extension
 * and provides a consistent interface for streaming data from large files.
 */
class JsonIteratorFactory
{
    /**
     * Constructor.
     *
     * @param bool $debug Whether to enable debug mode for the underlying iterators.
     */
    public function __construct(private bool $debug = false)
    {
    }

    /**
     * Creates an iterator for the given file path.
     *
     * Detects the file type based on the extension and returns the appropriate
     * streaming iterator.
     *
     * @param string $filePath The path to the JSON or JSONL file.
     * @return iterable<int,array<string,mixed>> An iterator that yields records from the file.
     */
    public function create(string $filePath): iterable
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($ext === 'jsonl') {
            return $this->createJsonlIterator($filePath);
        }

        return $this->createJsonStreamingIterator($filePath);
    }

    /**
     * Creates a generator for reading newline-delimited JSON (JSONL) files.
     *
     * This method reads the file line by line, decoding each line as a separate
     * JSON object.
     *
     * @param string $path The path to the JSONL file.
     * @return Generator<int,array<string,mixed>> A generator that yields records.
     */
    private function createJsonlIterator(string $path): Generator
    {
        $handle = fopen($path, 'rb');

        if (!$handle) {
            throw new RuntimeException("Failed to open JSONL file: {$path}");
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $record = json_decode($line, true);

            if ($record === null) {
                throw new RuntimeException("Invalid JSON on line: {$line}");
            }

            yield $record;
        }

        fclose($handle);
    }

    /**
     * Creates a streaming iterator for standard JSON files using JsonMachine.
     *
     * @param string $jsonPath The path to the JSON file.
     * @return Items An iterator from the JsonMachine library.
     */
    private function createJsonStreamingIterator(string $jsonPath): Items
    {
        return Items::fromFile($jsonPath, [
            'decoder' => new ExtJsonDecoder(true), // Use the faster ext-json decoder
            'debug' => $this->debug,
        ]);
    }
}
