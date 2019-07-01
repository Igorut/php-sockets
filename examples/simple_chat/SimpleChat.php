<?php

use PhpSockets\Client\Client;
use PhpSockets\Client\ClientCollection;
use PhpSockets\ClientCollectionInterface;
use PhpSockets\ClientInterface;
use PhpSockets\Daemon\DaemonTrait;
use \PhpSockets\Daemon\DaemonInterface;
use PhpSockets\Socket\WebSocket;

class SimpleChat extends WebSocket implements DaemonInterface
{
    use DaemonTrait;

    private $pid;

    protected function onOpen(ClientInterface $client): void
    {
        /** @var ClientInterface $existClient */
        foreach ($this->clients as $existClient) {
            if ($client !== $existClient) {
                $this->write($existClient, $this->encode(sprintf("let's welcome the new chat participant %s", $client->getAddress())));
            }
        }
    }

    protected function onMessage(ClientInterface $client, $message): void
    {
        /** @var ClientInterface $existClient */
        foreach ($this->clients as $existClient) {
            $this->write($existClient, $this->encode(sprintf('%s said: %s', $existClient->getAddress(), $message)));
        }
    }

    protected function onClose(ClientInterface $client): void
    {
        foreach ($this->clients as $existClient) {
            $this->write($existClient, $this->encode(sprintf('%s is leaved', $client->getAddress())));
        }
    }

    protected function getClient($connection): ClientInterface
    {
        return new Client($connection);
    }

    protected function getClientCollection(): ClientCollectionInterface
    {
        return new ClientCollection(new \SplObjectStorage());
    }

    protected function doAction(ClientInterface $client)
    {
        // do something on each user survey
    }

    protected function signalCallbacks(): void
    {
        pcntl_signal(SIGTERM, \Closure::bind(function ($signo) {
            foreach ($this->clients as $client) {
                $this->closeConnectionByServer($client, self::STATUS_CODE_GOING_AWAY);
            }

            $this->shutdown($this->socket);

            exit(0);
        }, $this, __CLASS__));
    }

    protected function getDaemonPid(): string
    {
        return $this->pid;
    }

    protected function setDaemonPid($pid)
    {
        $this->pid = $pid;
    }
}