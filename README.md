# socket_for_linux
一个用来快速搭建websocket和http服务器的框架，只需要几行代码就可以搭建一个服务器。使用多进程和IO复用来实现高并发；使用共享内存进行进程间的通信。支持守护进程，支持服务器的平滑重启。<br>

## Usage
####特性
1、多进程+IO复用，高并发
2、HTTP长连接
3、支持虚拟主机配置
4、支持前台进程和守护进程两种方式运行，可以随时监控服务器运行状态，支持平滑重启
5、异步发送数据，在发送大文件时不会阻塞

### Http服务器的使用
进入到根目录中，运行php http.php start即可开启http服务<br />

#### 服务器参数配置
除了使用网站根目录的http.php外，你也可以编写自己的入口文件，下面是服务器配置介绍<br>

```php
//初始化一个服务器
$server = new \Wangjian\Socket\WorkerServer('http://115.29.198.111:11111');

//配置worker进程数
$server->wokers = 4;

//服务器是否以守护进程方式运行
$server->deamon = false;

//连接的timeout值，默认为60秒
$server->timeout = 60;

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
```

### Websocket服务器的使用
#### 服务器的运行
进入到根目录中，运行php websocket.php start即可开启服务<br>

#### 服务器参数配置
除了使用网站根目录的websocket.php外，你也可以编写自己的入口文件，下面是服务器配置介绍<br>

```php
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
```

## 服务器运行情况监视可以
可以通过调用php http.php status来查看服务器的运行情况<br>

## 服务器关闭
可以通过调用php http.php stop来停止服务器<br>

## 平滑重启
可以通过调用php http.php restart来平滑重启服务器<br>
