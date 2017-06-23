<?php
require_once __DIR__.'/vendor/autoload.php';

//初始化一个服务器
$server = new \Wangjian\Socket\WorkerServer('ws://115.29.198.111:11111');

//配置worker进程数
$server->wokers = 2;

//服务器是否以守护进程方式运行
$server->deamon = false;

//连接的timeout值，默认为60秒
$server->timeout = 3600;

//服务器接受客户端连接时调用的回调函数
$server->onConnection = function($connection) {
    $pid = posix_getpid();
    echo "process $pid accept the connection from the client ".$connection->getRemoteAddress()."\r\n";
};

//服务器接受接收客户端消息时触发的回调函数
$server->onMessage = function($connection, $message) {
    echo "message from client: $message\r\n";
    $connection->sendString($message);
};

//服务器连接出错时触发的回调函数
$server->onError = function() {};

//服务器连接关闭时触发的回调函数
$server->onClose = function() {};

//运行
$server->runAll();