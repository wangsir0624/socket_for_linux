<?php
namespace Wangjian\Socket\Connection;

use Wangjian\Socket\EventLoop\EventLoopInterface;
use RuntimeException;

class Connection implements ConnectionInterface {
    /**
     * the connection socket stream
     * @var resource
     */
    public $stream;

    /**
     * the server which this connection belongs to
     * @var ServerInterface
     */
    public $server;

    /**
     * receive buffer
     * @var string
     */
    public $recv_buffer = '';

    /**
     * receive buffer size
     * @var int
     */
    public $recv_buffer_size = 1048576;

    /**
     * the size of the current package
     * @var int
     */
    private $current_package_size;

    /**
     * constructor
     * @param ServerInterface $server
     */
    public function __construct($server) {
        $this->server = $server;
        $this->stream = @stream_socket_accept($this->server->stream, $this->server->connectionTimeout, $peername);

        if(!$this->stream) {
            if(is_callable($this->server->onError)) {
                call_user_func($this->server->onError, $this, "create connection to $peername failed.");
            }

            throw new RuntimeException('stream_socket_accept() failed');
        }

        stream_set_read_buffer($this->stream, 0);
    }

    /**
     * send message to the client
     * @param sting buffer
     * @param string $raw  whether encode the buffer with the protocol
     * @return int the length of send data
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
     * close the connection
     */
    public function close() {
        $this->server->sm->decrement('current_connections');

        $this->server->connections->detach($this);

        $this->server->loop->delete($this->stream, EventLoopInterface::EV_READ);

        fclose($this->stream);
    }

    /**
     * called when the connection receive the client data
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
     * get the client address, including IP and port
     * @return string
     */
    public function getRemoteAddress() {
        return stream_socket_get_name($this->stream, true);
    }

    /**
     * get the client IP
     * @return string
     */
    public function getRemoteIp() {
        return substr($this->getRemoteAddress(), 0, strpos($this->getRemoteAddress(), ':'));
    }

    /**
     * get the client port
     * @return string
     */
    public function getRemotePort() {
        return substr($this->getRemoteAddress(), strpos($this->getRemoteAddress(), ':')+1);
    }
}