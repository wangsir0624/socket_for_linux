<?php
namespace Server;
use Connection\ConnectionInterface;
use EventLoop\EventLoopInterface;
use EventLoop\StreamSelectLoop;
use RuntimeException;
use Connection\Connection;
use SplObjectStorage;
use EventLoop\EventLoopFactory;

class Server implements ServerInterface {
    /**
     * @var stream
     * 服务器套接字
     */
    public $stream;

    /**
     * @var string
     * 服务器IP地址
     */
    private $ip;

    /**
     * @var int
     * 服务器监听的端口值
     */
    private $port;

    /**
     * 服务器回调函数
     * onConnection onMessage onError onClose这四个回调函数的第一个参数均为当前的connection实例
     */

    /**
     * @var callable
     * 服务器与客户端建立连接时触发的回调函数
     */
    public $onConnection;

    /**
     * @var callable
     * 服务器连接接受客户端消息时的回调函数
     * 接受两个参数，第一个为connection，第二个为接收到的服务器消息message
     */
    public $onMessage;

    /**
     * @var callable
     * 服务器连接关闭时的回调函数
     */
    public $onClose;

    /**
     * @var callable
     * 服务器连接出现错误时触发的回调函数
     * 接受两个参数，第一个为connection，第二个为错误信息error
     */
    public $onError;

    /**
     * @var SplObjectStorage
     * 管理server下的所有连接
     */
    public $connections;

    /**
     * @var EventLoopInterface
     * 用来监视套接字IO事件
     */
    public $loop;

    /**
     * @var int
     * 客户端连接服务器的超时时间
     * 如果超过这个时间，那么stream_socket_accept就会连接失败
     */
    public $connectionTimeout = 5;

    /**
     * 构造函数
     * @param string $ip 服务器IP地址
     * @param int $port 服务器端口
     */
    public function __construct($ip, $port) {
        $this->ip = $ip;
        $this->port = $port;
        $this->connections = new SplObjectStorage();
        $this->loop = EventLoopFactory::createLoop();
    }

    /**
     * 服务器开始监听
     */
    public function listen() {
        //创建服务器套接字
        $stream = stream_socket_server("tcp://$this->ip:$this->port", $errno, $errstr);
        if(!$stream) {
            throw new RuntimeException('create socket server failed.');
        }

        $this->stream = $stream;
        stream_set_blocking($this->stream, 0);

        $this->loop->addReadStream($stream, array($this, 'handleConnection'));
        $this->loop->run();
    }

    /**
     * 处理来自客户端的连接请求
     */
    public function handleConnection() {
        $connection = $this->createConnection();
        $this->connections->attach($connection);
        stream_set_blocking($connection->stream, 0);

        $this->loop->addReadStream($connection->stream, array($connection, 'handleMessage'));

        if(is_callable($this->onConnection)) {
            call_user_func($this->onConnection, $connection);
        }
    }

    /**
     * 创建连接
     * @return ConnectionInterface
     */
    private function createConnection() {
        return new Connection($this);
    }

    /**
     * 设置客户端连接超时时间
     * @param $timeout  超时事件，单位为秒
     */
    public function setConnectionTimeout($timeout) {
        $this->connectionTimeout = $timeout;
    }
}