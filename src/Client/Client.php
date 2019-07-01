<?php

namespace PhpSockets\Client;

use PhpSockets\ClientInterface;

class Client implements \JsonSerializable, ClientInterface
{
    /**
     * @var resource
     */
    private $connection;

    /**
     * @var int
     */
    private $port;

    /**
     * @var string
     */
    private $address;

    /**
     * @var int
     */
    private $connectedTime;

    public function __construct($connection)
    {
        socket_getpeername($connection, $address);
        $this->connection = $connection;
        $this->address = $address;
        $this->connectedTime = time();
    }

    /**
     * @return mixed
     */
    public function getConnectedTime(): int
    {
        return $this->connectedTime;
    }

    /**
     * @return resource
     */
    public function getConnection()
    {
        return $this->connection;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    /**
     * @return array|mixed
     */
    public function jsonSerialize()
    {
        return [
            'address' => $this->address,
            'port' => $this->port,
            'connected_time' => $this->connectedTime,
        ];
    }
}