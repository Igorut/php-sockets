<?php

namespace PhpSockets;

interface LoggerInterface
{
    /**
     * @param string $message
     */
    public function info(string $message): void;

    /**
     * @param string $message
     */
    public function error(string $message): void;
}