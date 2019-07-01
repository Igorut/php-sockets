<?php

namespace PhpSockets\Daemon;

use PhpSockets\Daemon\Exception\DaemonException;

/**
 * Implementation of DaemonInterface that implements methods to start and stop the daemon
 *
 * @package PhpSockets\Daemon
 */
trait DaemonTrait
{
    /**
     * Setup signal callbacks
     *
     * @return void
     */
    abstract protected function signalCallbacks(): void;

    /**
     * @return string
     */
    abstract protected function getDaemonPid(): string;

    /**
     * @param $pid
     *
     * @return mixed
     */
    abstract protected function setDaemonPid($pid);

    /**
     * @inheritdoc
     *
     * @throws DaemonException
     */
    public function startDaemon(callable $startMethod): void
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new DaemonException('PID is `-1`! Can\'t start daemon');
        }

        if ($pid) {
            $this->setDaemonPid($pid);

            return;
        }

        posix_setsid();
        pcntl_async_signals(true);

        $this->signalCallbacks();
        $startMethod();

        exit(0);
    }

    /**
     * @inheritdoc
     */
    public function stopDaemon(): void
    {
        $daemonPid = $this->getDaemonPid();

        if ($daemonPid && !posix_kill($daemonPid, SIGTERM)) {
            fwrite(STDOUT, sprintf('Unable to kill daemon with pid %s! Continue... %s', $daemonPid, PHP_EOL));
        }
    }
}