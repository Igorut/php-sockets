<?php

namespace PhpSockets\Socket;

use PhpSockets\ClientCollectionInterface;
use PhpSockets\ClientInterface;
use PhpSockets\Socket\Exception\SocketException;

abstract class AbstractSocket
{
    protected const MAX_MESSAGE_LENGTH = 8192;

    /**
     * @var resource
     */
    protected $socket;

    /**
     * @var ClientCollectionInterface
     */
    protected $clients;

    /**
     * @var string
     */
    private $address;

    /**
     * @var int
     */
    private $port;

    /**
     * @var array
     */
    private $options = [];

    /**
     * @var bool
     */
    private $isListening = false;

    /**
     * @var int
     */
    private $domain;

    /**
     * @var int
     */
    private $type;

    /**
     * @var int
     */
    private $protocol;

    /**
     * Initialize socket connection listener
     *
     * @return void
     */
    abstract public function start(): void;

    /**
     * Event on connection open
     *
     * The client connection is already open and functioning
     *
     * @param ClientInterface $client
     */
    abstract protected function onOpen(ClientInterface $client): void;

    /**
     * Event on message from client
     *
     * @param ClientInterface $client
     * @param $message
     */
    abstract protected function onMessage(ClientInterface $client, $message): void;

    /**
     * Event on connection close
     *
     * Don't try to communicate with the client here!
     *
     * @param ClientInterface $client
     */
    abstract protected function onClose(ClientInterface $client): void;

    /**
     * Fabric method to get a client instance
     *
     * @param resource $connection
     *
     * @return ClientInterface
     */
    abstract protected function getClient($connection): ClientInterface;

    /**
     * Fabric method to get a client collection instance
     *
     * @return ClientCollectionInterface
     */
    abstract protected function getClientCollection(): ClientCollectionInterface;

    /**
     * Socket constructor.
     *
     * @param int $domain
     * @param int $type
     * @param int $protocol
     *
     * @throws SocketException
     */
    public function __construct(int $domain, int $type, int $protocol)
    {
        if (!$this->socket = @socket_create($domain, $type, $protocol)) {
            throw new SocketException(sprintf('Can\'t create socket: %s', $this->getLastErrorMessage()));
        }

        $this->domain = $domain;
        $this->type = $type;
        $this->protocol = $protocol;
        $this->clients = $this->getClientCollection();
    }

    /**
     * @param ClientInterface $client
     * @param int $flag
     *
     * @return string|bool
     */
    protected function read(ClientInterface $client, $flag = MSG_DONTWAIT)
    {
        $result = '';
        $connection = $client->getConnection();

        while (true) {
            $bitesLength = @socket_recv($connection, $data, self::MAX_MESSAGE_LENGTH, $flag);

            // there is no more data
            if ($bitesLength === false) {
                break;
            }

            // if we receive a zero bites length, it means that a client is disconnected
            if ($bitesLength === 0) {
                return false;
            }

            $result .= $data;
        }

        return $result;
    }

    /**
     * @param ClientInterface $client
     * @param string $message
     *
     * @return bool
     *
     * @throws SocketException
     */
    protected function write(ClientInterface $client, string $message): bool
    {
        $messageLength = mb_strlen($message);

        if ($messageLength === false && !extension_loaded('mbstring')) {
            throw new SocketException('Please install php extension mbstring for correct work!');
        }

        $connection = $client->getConnection();

        // Sometimes socket_write can't send a full message in the one iteration, so, we do it in a loop
        while (true) {
            $sentLength = @socket_write($connection, $message, $messageLength);

            if ($sentLength === false) {
                return false;
            }

            if ($sentLength >= $messageLength) {
                return true;
            }

            $message = mb_substr($message, $sentLength);
            $messageLength -= $sentLength;
        }

        return false;
    }

    protected function shutdown($socket, int $how = 2): bool
    {
        $ok = socket_shutdown($socket, $how);
        socket_close($socket);

        return $ok;
    }

    /**
     * @param null $socket
     *
     * @return string
     */
    protected function getLastErrorMessage($socket = null): string
    {
        $errorMessage = socket_strerror(socket_last_error($socket));
        socket_clear_error($socket);

        return $errorMessage;
    }

    /**
     * Final touches and closing connection
     *
     * @param ClientInterface $client
     */
    final protected function closeConnection(ClientInterface $client): void
    {
        $this->clients->detach($client);
        $this->shutdown($client->getConnection());
        $this->onClose($client);
    }

    /**
     * Bind a socket to an address and port
     *
     * @param string $address
     * @param int $port
     *
     * @return AbstractSocket
     *
     * @throws SocketException
     */
    public function bind(string $address, int $port): AbstractSocket
    {
        if (!@socket_bind($this->socket, $address, $port)) {
            throw new SocketException($this->getLastErrorMessage($this->socket));
        }

        $this->address = $address;
        $this->port = $port;

        return $this;
    }

    /**
     * Set option to a socket
     *
     * Warning! You should use it BEFORE bind method and before start listening, else option will not be set
     *
     * @param int $level
     * @param string $optionName
     * @param int $optionValue
     *
     * @return AbstractSocket
     *
     * @throws SocketException
     */
    public function setOption(int $level, string $optionName, int $optionValue): AbstractSocket
    {
        if ($this->address !== null || $this->port !== null) {
            throw new SocketException('You cannot set options when socket is already bind');
        }

        if ($this->isListening) {
            throw new SocketException('You cannot set options while socket is listening');
        }

        if (!@socket_set_option($this->socket, $level, $optionName, $optionValue)) {
            throw new SocketException($this->getLastErrorMessage($this->socket));
        }

        $this->options[$optionName] = $optionValue;

        return $this;
    }

    /**
     * Setting block mode
     *
     * true -> activate block mode
     * false -> deactivate block mode
     *
     * @param bool $block
     *
     * @return AbstractSocket
     *
     * @throws SocketException
     */
    public function setBlockMode(bool $block = true): AbstractSocket
    {
        if (!($block ? @socket_set_block($this->socket) : @socket_set_nonblock($this->socket))) {
            throw new SocketException($this->getLastErrorMessage($this->socket));
        }

        return $this;
    }

    /**
     * Start listening socket
     *
     * @throws SocketException
     */
    public function listen(): void
    {
        if ($this->type !== SOCK_STREAM && $this->type !== SOCK_SEQPACKET) {
            /**
             * @see https://secure.php.net/manual/en/function.socket-listen.php
             */
            throw new SocketException('You can use listen method only on sockets with types: SOCK_STREAM and SOCK_SEQPACKET');
        }

        if (!@socket_listen($this->socket)) {
            throw new SocketException($this->getLastErrorMessage($this->socket));
        }

        $this->isListening = true;
    }

    /**
     * @return string
     */
    public function getAddress(): string
    {
        return $this->address;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @return int
     */
    public function getDomain(): int
    {
        return $this->domain;
    }

    /**
     * @return int
     */
    public function getProtocol(): int
    {
        return $this->protocol;
    }

    /**
     * Get already set options
     *
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @return bool
     */
    public function isListening(): bool
    {
        return $this->isListening;
    }

    public function __destruct()
    {
        foreach ($this->clients as $client) {
            $this->closeConnection($client);
        }
    }
}