#socket_for_linux
#一个用来快速搭建websocket和http服务器的框架，只需要几行代码就可以搭建一个服务器。使用多进程和IO复用来实现高并发；使用共享内存进行进程间的通信。支持守护进程，支持服务器的平滑重启。

#Usage
#Websocket服务器的使用
#服务器的运行
#进入到根目录中，运行php index.php start即可开启服务

#服务器参数配置
#除了使用网站根目录的index.php外，你也可以编写自己的入口文件，下面是服务器配置介绍

//初始化一个服务器
$server = new \Server\WorkerServer('ws://127.0.0.1:8000');

//配置worker进程数
$server->wokers = 2;

//服务器是否以守护进程方式运行
//$server->deamon = false;

//服务器接受客户端连接时调用的回调函数
$server->onConnection = function($connection) {
    $pid = posix_getpid();
    echo "process $pid accept the connection from the client ".$connection->getRemoteAddress()."\r\n";
};

//服务器接受接收客户端消息时触发的回调函数
$server->onMessage = function($connection, $message) {
    echo "message from client: $message\r\n";
    $connection->send($message);
};

//服务器连接出错时触发的回调函数
$server->onError = function() {}

//服务器连接关闭时触发的回调函数
$server->onClose = function() {}

//运行
$server->runAll();


#服务器运行情况监视可以
#可以通过调用php index.php status来查看服务器的运行情况

#服务器关闭
#可以通过调用php index.php stop来停止服务器

#平滑重启
#可以通过调用php index.php restart来平滑重启服务器
