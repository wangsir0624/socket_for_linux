<?php
use Server\Server;

//引入自动加载文件
require_once './autoload.php';

$websocket_server = new Server('ws://127.0.0.1:8000');

$websocket_server->onClose = function($connection) {
    $address = $connection->getRemoteAddress();

    echo "connection from $address closed.\r\n";
};

$websocket_server->onMessage = function($connection, $message) {
    echo "message from client: $message\r\n";
    $connection->send($message.'111');
};

$websocket_server->onError = function($connection, $error) {
    $address = $connection->getRemoteAddress();

    echo "connection from $address failed: $error\r\n";
};

$websocket_server->onConnection = function($connection) {
    $address = $connection->getRemoteAddress();

    echo "connection from $address created\r\n";
};

$websocket_server->listen();