<?php

namespace PhpSockets;

interface ClientInterface
{
    /**
     * @return resource
     */
    public function getConnection();

    /**
     * @return int
     */
    public function getConnectedTime(): int;

    /**
     * @return string
     */
    public function getAddress(): string;
}