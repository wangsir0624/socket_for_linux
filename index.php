<?php
use Server\Server;

//引入自动加载文件
require_once './autoload.php';

$server = new \Server\WorkerServer('ws://127.0.0.1:8000');

$server->wokers = 2;
//$server->deamon = false;
$server->onConnection = function($connection) {
    $pid = posix_getpid();
    echo "process $pid accept the connection from the client ".$connection->getRemoteAddress()."\r\n";
};

$server->onMessage = function($connection, $message) {
    echo "message from client: $message\r\n";
    $connection->send($message);
};
$server->runAll();