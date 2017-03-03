<?php
namespace Connection;

use EventLoop\EventLoopInterface;
use Server\ServerInterface;

class Connection implements ConnectionInterface {
    /**
     * @var resource
     * 连接套接字
     */
    public $stream;

    /**
     * @var ServerInterface
     * 连接所属的服务器
     */
    public $server;

    public $recv_buffer = '';

    public $recv_buffer_size = 1048576;

    private $current_package_size;

    /**
     * 构造函数
     * @param ServerInterface $server
     */
    public function __construct($server) {
        $this->server = $server;
        $this->stream = stream_socket_accept($this->server->stream, $this->server->connectionTimeout, $peername);

        //如果连接失败
        if(!$this->stream) {
            if(is_callable($this->server->onError)) {
                call_user_func($this->server->onError, $this, "create connection to $peername failed.");
            }
        }

        stream_set_read_buffer($this->stream, 0);
    }

    /**
     * 发送数据
     * @buffer
     */
    public function send($buffer, $raw = false) {
        if($buffer) {
            if(!$raw) {
                $protocol = $this->server->protocol;
                $buffer = $protocol::encode($buffer, $this);
            }

            $len = strlen($buffer);
            $writeLen = 0;
            while (($data = fwrite($this->stream, substr($buffer, $writeLen), $len - $writeLen))) {
                $writeLen += $data;
                if ($writeLen >= $len) {
                    break;
                }
            }

            return $writeLen;
        }

        return 0;
    }

    /**
     * 关闭连接
     */
    public function close() {
        $this->server->connections->detach($this);

        $this->server->loop->delete($this->stream, EventLoopInterface::EV_READ);

        fclose($this->stream);
    }

    /**
     * 连接接收到数据时调用的回调函数
     */
    public function handleMessage() {
        $buffer = fread($this->stream, $this->recv_buffer_size);

        $this->recv_buffer .= $buffer;

        $protocol = $this->server->protocol;

        $this->current_package_size = $protocol::input($this->recv_buffer, $this);

        if($this->current_package_size != 0) {
            $buffer = substr($this->recv_buffer, 0, $this->current_package_size);
            $this->recv_buffer = substr($this->recv_buffer, $this->current_package_size);
            $this->current_package_size = 0;

            $protocol::decode($buffer, $this);

            if(!empty($this->recv_buffer)) {
                call_user_func(array($this, 'handleMessage'));
            }
        }
    }

    /**
     * 获取客户端的地址，包括IP和端口
     * @return string
     */
    public function getRemoteAddress() {
        return stream_socket_get_name($this->stream, true);
    }

    /**
     * 获取客户端的IP
     * @return string
     */
    public function getRemoteIp() {
        return substr($this->getRemoteAddress(), 0, strpos($this->getRemoteAddress(), ':'));
    }

    /**
     * 获取客户端的端口
     * @return string
     */
    public function getRemotePort() {
        return substr($this->getRemoteAddress(), strpos($this->getRemoteAddress(), ':')+1);
    }
}