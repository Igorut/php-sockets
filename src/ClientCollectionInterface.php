<?php

namespace PhpSockets;

interface ClientCollectionInterface extends \Countable
{
    /**
     * @param ClientInterface $client
     */
    public function attach(ClientInterface $client): void;

    /**
     * @param ClientInterface $client
     */
    public function detach(ClientInterface $client): void;
}