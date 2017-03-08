<?php
namespace Server;

use Connection\ConnectionInterface;
use EventLoop\EventLoopInterface;
use EventLoop\StreamSelectLoop;
use RuntimeException;
use Connection\Connection;
use SplObjectStorage;
use EventLoop\EventLoopFactory;
use Protocol\WebSocketProtocol;
use Protocol\TextProtocol;

class Worker {
    /**
     * 支持的服务器类型
     * 为一个数组映射，键为shema，值为对应的服务器类名
     * @const array
     */
    protected static $protocols = array(
        'tcp' => TextProtocol::class,
        'ws' => WebSocketProtocol::class
    );

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
     * Application protocol classname
     * @var string
     */
    public $protocol;

    /**
     * 创建服务器实例
     * @param string uri 此参数形式为scheme//ip:port 例如tcp://127.0.0.1:8000
     * @return ServerInterface
     */
    public function __construct($uri) {
        /**
         * uri由schema，IP和port组成
         *解析uri
         */
        $schema = substr($uri, 0, strpos($uri, "://"));
        $address = substr($uri, strpos($uri, "://")+3);
        list($ip, $port) = explode(':', $address);

        //如果uri不符合规则，则跑出InvalidAugumentException
        if(empty($schema) || empty($ip) || empty($port)) {
            throw new InvalidArgumentException('the argument is not correct.');
        }

        //根据shema，实例化对应的服务器类
        $protocolName = @self::$protocols[$schema];
        if(empty($protocolName)) {
            throw new RuntimeException('unsupported application protocol.');
        }

        $this->ip = $ip;
        $this->port = $port;
        $this->protocol = $protocolName;
        $this->loop = EventLoopFactory::createLoop();
        $this->connections = new SplObjectStorage();
    }

    public function createSocket() {
        //创建服务器套接字
        $stream = @stream_socket_server("tcp://$this->ip:$this->port", $errno, $errstr);
        if(!$stream) {
            throw new RuntimeException('create socket server failed.');
        }

        $this->stream = $stream;
        stream_set_blocking($this->stream, 0);
    }

    public function closeSocket() {
        if(is_resource($this>$this->stream)) {
            fclose($this->stream);
        }
    }

    /**
     * 服务器开始监听
     */
    public function listen() {
        $this->loop->add($this->stream, EventLoopInterface::EV_READ, array($this, 'handleConnection'));
        $this->loop->run();
    }

    /**
     * 处理来自客户端的连接请求
     */
    public function handleConnection() {
        try {
            $connection = $this->createConnection();
        } catch(\Exception $e) {
            $this->sm->increment('failed_connections');
            $this->sm->increment('total_connections');
            return;
        }
        $this->sm->increment('current_connections');
        $this->sm->increment('total_connections');

        $this->connections->attach($connection);
        stream_set_blocking($connection->stream, 0);

        $this->loop->add($connection->stream, EventLoopInterface::EV_READ, array($connection, 'handleMessage'));
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