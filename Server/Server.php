<?php
namespace Server;
use RuntimeException;

class Server implements ServerInterface {
    /**
     * 服务器套接字
     * @var stream
     */
    private $stream;

    /**
     * 服务器event base
     * @var resource
     */
    private $base;

    /**
     * 服务器IP地址
     * @var string
     */
    private $ip;

    /**
     * 服务器监听的端口值
     * @var int
     */
    private $port;

    /**
     * 服务器回调函数
     * 回调函数的第一个参数为Connection对象
     */

    /**
     * 服务器接受客户端连接请求时的回调函数
     * @var callable
     */
    public $onConnection;

    /**
     * 服务器接受客户端消息时的回调函数
     * @var callable
     */
    public $onMessage;

    /**
     * 服务器连接关闭时的回调函数
     * @var callable
     */
    public $onClose;

    /**
     * 构造函数
     * @param string $ip 服务器IP地址
     * @param int $port 服务器端口
     */
    public function __construct($ip, $port) {
        $this->ip = $ip;
        $this->port = $port;
    }

    public function listen() {
        //创建服务器套接字
        $stream = stream_socket_server("tcp://$this->ip:$this->port", $errno, $errstr);
        if(!$stream) {
            throw new RuntimeException('创建服务器套接字失败！');
        }

        $this->stream = $stream;
        stream_set_blocking($this->stream, 0);

        //利用libevent库监视服务器套接字IO
        $this->base = event_base_new();
        $event = event_new();
        event_set($event, $this->stream, EV_READ | EV_PERSIST, array($this, 'handleConnection'));
        event_base_set($event, $this->base);
        event_add($event);
        event_base_loop($this->base);
    }

    public function shutdown() {
    }

    public function handleConnection() {
        $stream = stream_socket_accept($this->stream);
        echo (int)$stream;
    }
}