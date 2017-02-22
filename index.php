<?php
use Server\ServerFactory;

//引入自动加载文件
require_once './autoload.php';

$server = ServerFactory::server('tcp//127.0.0.1:8000');

$server->onConnection = function($connection) {
    echo "接受来自".$connection->getRemoteAddress()."的连接\r\n";
};

$server->onMessage = function($connection, $message) {
    echo "message from client: $message\r\n";
    $connection->sendMsg($message);
};

$server->listen();