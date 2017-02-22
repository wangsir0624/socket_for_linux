<?php
namespace Connection;

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
    }

    /**
     * 发送数据
     * @buffer
     */
    public function sendMsg($buffer) {
        $len = strlen($buffer);
        $writeLen = 0;
        while(($data = fwrite($this->stream, substr($buffer, $writeLen), $len-$writeLen))) {
            $writeLen += $data;
            if($writeLen >= $len) {
                break;
            }
        }

        return $writeLen;
    }

    /**
     * 关闭连接
     */
    public function handleClose() {
        if(is_callable($this->server->onClose)) {
            call_user_func($this->server->onClose, $this);
        }

        $this->server->connections->detach($this);

        $this->server->loop->removeReadStream($this->stream);

        fclose($this->stream);
    }

    /**
     * 连接接收到数据时调用的回调函数
     */
    public function handleMessage() {
        $message = fread($this->stream, 1024);

        //触发回调函数
        if($message == '') {
            $this->handleClose();
        } else {
            if (is_callable($this->server->onMessage)) {
                call_user_func($this->server->onMessage, $this, $message);
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