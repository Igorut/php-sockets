<?php

use PhpSockets\Logger\DefaultLogger;

require '../../vendor/autoload.php';
require 'SimpleChat.php';

$address = '127.0.0.1';
$port = 2048;
$chat = new SimpleChat(new DefaultLogger());
$chat->setOption(SOL_SOCKET, SO_REUSEADDR, 1)
    ->setOption(SOL_SOCKET, SO_REUSEPORT, 1)
    ->bind($address, $port)
    ->setBlockMode(false)
    ->listen();

//$chat->startDaemon([$chat, 'start']); be careful it's will be work in background, don't forget kill daemon
$chat->start();