<?php

namespace Silverstripe\JsonMigrate;

/**
 * A simple logger for debugging output.
 *
 * This class provides a basic logging mechanism that outputs messages to the console
 * when debug mode is enabled. It can be extended to support more advanced logging
 * features like writing to files or integrating with other logging libraries.
 */
class Logger
{
    /**
     * @var bool Whether debug mode is enabled.
     */
    protected bool $debug;

    /**
     * Constructor.
     *
     * @param bool $debug Whether to enable debug mode.
     */
    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
    }

    /**
     * Logs a message if debug mode is enabled.
     *
     * @param string $message The message to log.
     */
    public function log(string $message): void
    {
        if (!$this->debug) {
            return;
        }

        echo "DEBUG: {$message}\n" . PHP_EOL;
    }
}
