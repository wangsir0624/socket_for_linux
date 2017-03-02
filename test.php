<?php
use Protocol\HttpProtocol;

//引入自动加载文件
require_once './autoload.php';

$fp1 = stream_socket_client("tcp://127.0.0.1:8000", $errno, $errstr, 30);

fwrite($fp1, "Hello World11231!\n");
echo "reply from server: ".fread($fp1, 1024)."\r\n";