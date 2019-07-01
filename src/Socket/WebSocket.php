<?php

namespace PhpSockets\Socket;

use PhpSockets\ClientInterface;
use PhpSockets\LoggerInterface;
use PhpSockets\Socket\Exception\SocketException;

abstract class WebSocket extends AbstractSocket
{
    protected const STATUS_CODE_NORMAL = 1000;
    protected const STATUS_CODE_GOING_AWAY = 1001;
    protected const STATUS_CODE_PROTOCOL_ERROR = 1002;
    protected const STATUS_CODE_UNKNOWN_OPCODE = 1003;
    protected const STATUS_CODE_FRAME_TOO_LARGE = 1004;
    protected const STATUS_CODE_UTF8_EXCEPTED = 1007;
    protected const STATUS_CODE_POLICY_VIOLATION = 1008;

    private const ADDITIONAL_KEY = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    private const MESSAGE_TYPE_CLOSE = 'close';
    private const MESSAGE_TYPE_TEXT = 'text';
    private const MESSAGE_TYPE_BINARY = 'binary';
    private const MESSAGE_TYPE_PING = 'ping';
    private const MESSAGE_TYPE_PONG = 'pong';

    /**
     * @var int
     */
    private $maxConnectionsFromIp;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(LoggerInterface $logger, $maxConnectionsFromIp = 0)
    {
        parent::__construct(AF_INET, SOCK_STREAM, SOL_TCP);

        $this->maxConnectionsFromIp = $maxConnectionsFromIp;
        $this->logger = $logger;
    }

    /**
     * Do something on each user survey
     *
     * @param ClientInterface $client
     *
     * @return mixed
     */
    abstract protected function doAction(ClientInterface $client);

    /**
     * @inheritDoc
     *
     * @throws SocketException
     */
    public function start(): void
    {
        if (!$this->isListening()) {
            throw new SocketException('Socket isn\'t listening');
        }

        $socketsToExcept = null;
        $socketsToRead = $socketsToWrite = [$this->socket];

        // socket_select will stopping the loop until something happens
        while (@socket_select($socketsToRead, $socketsToWrite, $socketsToExcept, null)) {
            // If socket have new connections, process it
            while ($connection = @socket_accept($this->socket)) {
                $client = $this->getClient($connection);
                $existCount = $this->existClientCount($client);

                if ($this->maxConnectionsFromIp === 0 || $existCount < $this->maxConnectionsFromIp) {
                    if ($this->handshake($client) === false) {
                        $this->logger->info(sprintf('an error occurred while handshaking with a client %s', $client->getAddress()));

                        continue;
                    }

                    $this->clients->attach($client);
                    $this->onOpen($client);
                    $this->logger->info(sprintf('a new client with an address %s', $client->getAddress()));
                } else {
                    $this->logger->error(sprintf('client %s is already exist', $client->getAddress()));
                }
            }

            foreach ($this->clients as $client) {
                /** @var ClientInterface $client */
                $message = $this->read($client);

                // The client is disconnected
                if ($message === false) {
                    $this->closeConnection($client);
                    $this->logger->info(sprintf('connection with a client %s is destroyed', $client->getAddress()));

                    continue;
                }

                if (empty($message)) {
                    $this->doAction($client);

                    continue;
                }

                $decodedMessage = $this->decode($message);

                if (isset($decodedMessage['error'])) {
                    $this->logger->error($decodedMessage['error']);

                    continue;
                }

                if ($decodedMessage['type'] === self::MESSAGE_TYPE_CLOSE) {
                    $this->closeConnectionByClient($client, $decodedMessage['payload']);
                    $this->logger->info(sprintf('client %s is disconnected by himself', $client->getAddress()));

                    continue;
                }

                $this->onMessage($client, $decodedMessage['payload']);
            }

            // Update arrays for listening by socket_select()
            $socketsToRead = $this->getClientsConnections();
            $socketsToRead[] = $this->socket;
            $socketsToWrite = $socketsToRead;

            usleep(10000);
        }
    }

    /**
     * Close connection by client side
     *
     * @param $client
     * @param $payload
     *
     * @throws SocketException
     * @throws \Exception
     */
    protected function closeConnectionByClient(ClientInterface $client, $payload): void
    {
        $this->write($client, $this->encode($payload, self::MESSAGE_TYPE_CLOSE));

        $this->closeConnection($client);
    }

    /**
     * Close connection by server side
     *
     * @param $client
     * @param int $code
     *
     * @throws SocketException if has been passed unknown close code
     * @throws \Exception
     */
    protected function closeConnectionByServer(ClientInterface $client, $code = self::STATUS_CODE_NORMAL): void
    {
        // we can send custom messages here by custom codes... code should more than 1000-1015, they're reserved
        switch ($code) {
            case self::STATUS_CODE_NORMAL:
            case self::STATUS_CODE_GOING_AWAY:
            case self::STATUS_CODE_PROTOCOL_ERROR:
            case self::STATUS_CODE_UNKNOWN_OPCODE:
            case self::STATUS_CODE_FRAME_TOO_LARGE:
            case self::STATUS_CODE_UTF8_EXCEPTED:
            case self::STATUS_CODE_POLICY_VIOLATION:
                break;
            default:
                throw new SocketException(sprintf('Undefined close code: %s', $code));
                break;
        }

        $encodedCode = substr(pack('N', $code), 2);

        $this->write($client, $this->encode($encodedCode, self::MESSAGE_TYPE_CLOSE));

        $this->closeConnection($client);
    }

    /**
     * @param ClientInterface $client
     *
     * @return int
     */
    private function existClientCount(ClientInterface $client): int
    {
        $clientAddress = $client->getAddress();
        $existAddressCount = 0;

        /** @var ClientInterface $existClient */
        foreach ($this->clients as $existClient) {
            if ($clientAddress === $existClient->getAddress()) {
                $existAddressCount++;
            }
        }

        return $existAddressCount;
    }

    /**
     * @return array
     */
    private function getClientsConnections(): array
    {
        $connections = [];

        /** @var ClientInterface $client */
        foreach ($this->clients as $client) {
            $connections[] = $client->getConnection();
        }

        return $connections;
    }

    /**
     * Encode message for web socket protocol
     *
     * @param $payload
     * @param string $type
     * @param bool $masked
     *
     * @return array|string
     *
     * @throws \Exception
     */
    protected function encode($payload, $type = self::MESSAGE_TYPE_TEXT, $masked = false)
    {
        $frameHead = [];
        $payloadLength = strlen($payload);

        switch ($type) {
            case self::MESSAGE_TYPE_TEXT:
                // first byte indicates FIN, Text-Frame (10000001):
                $frameHead[0] = 129;
                break;

            case self::MESSAGE_TYPE_CLOSE:
                // first byte indicates FIN, Close Frame(10001000):
                $frameHead[0] = 136;
                break;

            case self::MESSAGE_TYPE_PING:
                // first byte indicates FIN, Ping frame (10001001):
                $frameHead[0] = 137;
                break;

            case self::MESSAGE_TYPE_PONG:
                // first byte indicates FIN, Pong frame (10001010):
                $frameHead[0] = 138;
                break;
        }

        // set mask and payload length (using 1, 3 or 9 bytes)
        if ($payloadLength > 65535) {
            $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
            $frameHead[1] = ($masked === true) ? 255 : 127;
            for ($i = 0; $i < 8; $i++) {
                $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
            }
            // most significant bit MUST be 0
            if ($frameHead[2] > 127) {
                return $this->error('frame too large (1004)');
            }
        } else if ($payloadLength > 125) {
            $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
            $frameHead[1] = ($masked === true) ? 254 : 126;
            $frameHead[2] = bindec($payloadLengthBin[0]);
            $frameHead[3] = bindec($payloadLengthBin[1]);
        } else {
            $frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
        }

        // convert frame-head to string:
        foreach (array_keys($frameHead) as $i) {
            $frameHead[$i] = chr($frameHead[$i]);
        }
        if ($masked === true) {
            // generate a random mask:
            $mask = [];
            for ($i = 0; $i < 4; $i++) {
                $mask[$i] = chr(random_int(0, 255));
            }

            $frameHead = array_merge($frameHead, $mask);
        }
        $frame = implode($frameHead);

        // append payload to frame:
        for ($i = 0; $i < $payloadLength; $i++) {
            $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        }

        return $frame;
    }

    /**
     * Decode a message received from an web socket
     *
     * @param $data
     *
     * @return array
     */
    private function decode($data): array
    {
        $unmaskedPayload = '';
        $decodedData = [];

        // estimate frame type:
        $firstByteBinary = sprintf('%08b', ord($data[0]));
        $secondByteBinary = sprintf('%08b', ord($data[1]));
        $opCode = bindec(substr($firstByteBinary, 4, 4));
        $isMasked = (int)$secondByteBinary[0] === 1;
        $payloadLength = ord($data[1]) & 127;

        // unmasked frame is received:
        if (!$isMasked) {
            return $this->error('protocol error (1002)');
        }

        switch ($opCode) {
            // text frame:
            case 1:
                $decodedData['type'] = self::MESSAGE_TYPE_TEXT;
                break;

            case 2:
                $decodedData['type'] = self::MESSAGE_TYPE_BINARY;
                break;

            // connection close frame:
            case 8:
                $decodedData['type'] = self::MESSAGE_TYPE_CLOSE;
                break;

            // ping frame:
            case 9:
                $decodedData['type'] = self::MESSAGE_TYPE_PING;
                break;

            // pong frame:
            case 10:
                $decodedData['type'] = self::MESSAGE_TYPE_PONG;
                break;

            default:
                return $this->error('unknown opcode (1003)');
        }

        if ($payloadLength === 126) {
            $mask = substr($data, 4, 4);
            $payloadOffset = 8;
            $dataLength = bindec(sprintf('%08b', ord($data[2])) . sprintf('%08b', ord($data[3]))) + $payloadOffset;
        } else if ($payloadLength === 127) {
            $mask = substr($data, 10, 4);
            $payloadOffset = 14;
            $tmp = '';

            for ($i = 0; $i < 8; $i++) {
                $tmp .= sprintf('%08b', ord($data[$i + 2]));
            }

            $dataLength = bindec($tmp) + $payloadOffset;
            unset($tmp);
        } else {
            $mask = substr($data, 2, 4);
            $payloadOffset = 6;
            $dataLength = $payloadLength + $payloadOffset;
        }

        if ($isMasked) {
            for ($i = $payloadOffset; $i < $dataLength; $i++) {
                $j = $i - $payloadOffset;
                if (isset($data[$i])) {
                    $unmaskedPayload .= $data[$i] ^ $mask[$j % 4];
                }
            }

            $decodedData['payload'] = $unmaskedPayload;
        } else {
            $payloadOffset -= 4;
            $decodedData['payload'] = substr($data, $payloadOffset);
        }

        return $decodedData;
    }

    /**
     * @param ClientInterface $client
     *
     * @return array|bool
     *
     * @throws SocketException
     */
    private function handshake(ClientInterface $client)
    {
        if ((false === $headers = $this->read($client)) || empty($headers)) {
            return false;
        }

        // We clear the array from null values
        $headers = array_diff(explode("\r\n", $headers), ['']);

        $headersLength = count($headers);
        $parsedHeaders = ['Method' => $headers[0]];

        for ($i = 1; $i < $headersLength; $i++) {
            [$key, $value] = explode(': ', $headers[$i]);

            $parsedHeaders[$key] = $value;
        }

        $secWebSocketAccept = base64_encode(pack('H*', sha1($parsedHeaders['Sec-WebSocket-Key'] . self::ADDITIONAL_KEY)));

        $handshake = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            'Sec-WebSocket-Accept: ' . $secWebSocketAccept . "\r\n\r\n";

        if (!$this->write($client, $handshake)) {
            return false;
        }

        return $parsedHeaders;
    }

    /**
     * @param $error
     *
     * @return array
     */
    private function error($error): array
    {
        return [
            'type' => '',
            'payload' => '',
            'error' => $error
        ];
    }
}