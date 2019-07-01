<?php

namespace PhpSockets\Daemon;

interface DaemonInterface
{
    /**
     * Start process as daemon
     *
     * @param callable $startMethod
     *
     * @return void
     */
    public function startDaemon(callable $startMethod): void;

    /**
     * Stop daemon process
     *
     * @return void
     */
    public function stopDaemon(): void;
}