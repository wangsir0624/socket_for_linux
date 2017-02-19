<?php
use Server\ServerFactory;

//引入自动加载文件
require_once './autoload.php';

$server = ServerFactory::server('tcp//127.0.0.1:8000');

$server->listen();