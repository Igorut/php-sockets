<?php

namespace PhpSockets\Logger;

use PhpSockets\LoggerInterface;

class DefaultLogger implements LoggerInterface
{
    private $destination;

    /**
     * Destination should be a resource
     *
     * @param resource $destination
     */
    public function __construct($destination = null)
    {
        if ($destination === null) {
            $destination = STDOUT;
        }

        $this->destination = $destination;
    }

    public function info(string $message): void
    {
        $this->write(sprintf('[INFO] %s', $message));
    }

    public function error(string $message): void
    {
        $this->write(sprintf('[ERROR] %s', $message));
    }

    private function write(string $message): void
    {
        fwrite($this->destination, $message . PHP_EOL);
    }
}