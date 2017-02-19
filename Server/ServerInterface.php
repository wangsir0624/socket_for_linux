<?php
namespace Server;

interface ServerInterface {
    //服务器开始监听请求
    function listen();

    //关闭服务器
    function shutdown();

    //处理来自客户端的连接请求
    function handleConnection();
}