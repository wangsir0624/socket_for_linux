<?php
require_once __DIR__.'/vendor/autoload.php';

//初始化一个服务器
$server = new \Wangjian\Socket\WorkerServer('http://115.29.198.111:11111');

//配置worker进程数
$server->wokers = 2;

//服务器是否以守护进程方式运行
$server->deamon = false;

//服务器配置
$server->hosts = array(
    'default' => [
        'root' => '/data/dmz/test/www',
        'index' => 'index.php index.html index.htm'
    ],

    'test.test.com' => [
        'root' => '/data/dmz/test/test',
        'index' => 'index.php index.html index.htm'
    ]
);

//运行
$server->runAll();