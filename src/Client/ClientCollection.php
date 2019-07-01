<?php

namespace PhpSockets\Client;

use PhpSockets\ClientCollectionInterface;
use PhpSockets\ClientInterface;

/**
 * Client collection
 *
 * @method \SplObjectStorage getInnerIterator()
 */
class ClientCollection extends \IteratorIterator implements \JsonSerializable, ClientCollectionInterface
{
    public function __construct(\SplObjectStorage $iterator)
    {
        parent::__construct($iterator);
    }

    public function attach(ClientInterface $client): void
    {
        $this->getInnerIterator()->attach($client);
    }

    public function detach(ClientInterface $client): void
    {
        $this->getInnerIterator()->detach($client);
    }

    public function jsonSerialize()
    {
        return [
            'items' => iterator_to_array($this->getInnerIterator())
        ];
    }

    public function count(): int
    {
        return $this->getInnerIterator()->count();
    }
}