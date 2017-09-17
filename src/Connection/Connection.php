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
     * the send buffer
     * @var string
     */
    protected $send_buffer = '';

    /**
     * the send file handlers
     * @var array
     */
    protected $fds = [];

    /**
     * the size of the current package
     * @var int
     */
    public $current_package_size;

    /**
     * the connection lifetime
     * @var int
     */
    public $timeout = 0;

    /**
     * the max requests
     * @var int
     */
    public $max_requests = 0;

    /**
     * the current requests accepted
     * @var int
     */
    public $requests = 0;

    /**
     * the timestamp when the connection was created
     * @var int
     */
    protected $connected_at;

    /**
     * constructor
     * @param ServerInterface $server
     */
    public function __construct($server) {
        $this->server = $server;
        $this->stream = @stream_socket_accept($this->server->stream, 5, $peername);

        if(!$this->stream) {
            if(is_callable($this->server->onError)) {
                call_user_func($this->server->onError, $this, "create connection to $peername failed.");
            }

            throw new RuntimeException('stream_socket_accept() failed');
        }

        stream_set_read_buffer($this->stream, 0);
        $this->connected_at = $this->last_recv_time = time();
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
            while($writeLen < $len) {
                $data = @fwrite($this->stream, substr($buffer, $writeLen, 8192), 8192);
                if($data === false) {
                    //return when the socket write buffer is empty
                    return $writeLen;
                } else if($data === 0) {
                    //close the socket when the client socket is closed
                    $this->close();
                    return $writeLen;
                }

                $writeLen += $data;
            }

            return $writeLen;
        }

        return 0;
    }

    /**
     * send string to the client
     * @param mixed $buffer
     * @param bool $raw  whether encode the buffer before sending
     */
    public function sendString($buffer, $raw = false) {
        if($buffer) {
            if(!$raw) {
                $protocol = $this->server->protocol;
                $buffer = $protocol::encode($buffer, $this);
            }

            $writeLen = $this->send($buffer, true);
            if($writeLen < strlen($buffer)) {
                if($this->isSendBufferEmpty()) {
                    call_user_func(array($this, 'onSendBufferNotEmpty'));
                }

                $this->send_buffer .= substr($buffer, $writeLen);
            }
        }
    }

    /**
     * send file to the client
     * @param resource $fd  the file handler
     */
    public function sendFile($fd) {
        if($this->isSendBufferEmpty()) {
            call_user_func(array($this, 'onSendBufferNotEmpty'));
        }

        $this->fds[] = $fd;
    }

    /**
     * write the connection send buffer to the socket write buffer
     */
    public function flushSendBuffer() {
        //when the send buffer is empty, send the file content
        if($this->send_buffer == '') {
            foreach ($this->fds as $key => $fd) {
                if (feof($fd)) {
                    fclose($fd);
                    unset($this->fds[$key]);
                    continue;
                }

                $this->send_buffer .= fread($fd, 8192);
                break;
            }
        }

        $writeLen = $this->send($this->send_buffer, true);
        $this->send_buffer = substr($this->send_buffer, $writeLen);

        //if the send buffer is empty, cancel monitoring the write event
        if($this->isSendBufferEmpty()) {
            call_user_func(array($this, 'onSendBufferEmpty'));
        }
    }

    /**
     * called when the connection send buffer is empty
     */
    public function onSendBufferEmpty() {
        $this->server->loop->delete($this->stream, EventLoopInterface::EV_WRITE);
    }

    /**
     * called when the connection send buffer is not empty
     */
    public function onSendBufferNotEmpty() {
        //monitoring for write event
        $this->server->loop->add($this->stream, EventLoopInterface::EV_WRITE, array($this, 'flushSendBuffer'));
    }

    /**
     * check whether the send buffer of the connection is empty
     * @return bool
     */
    public function isSendBufferEmpty() {
        if($this->send_buffer != '') {
            return false;
        }

        foreach($this->fds as $fd) {
            if(!feof($fd)) {
                return false;
            }
        }

        return true;
    }

    /**
     * whether the connection is timed out
     * @return bool
     */
    public function timedOut() {
        if($this->timeout > 0) {
            if(time() >= $this->timeout) {
                return true;
            }
        } else {
            if(time() >= ($this->connected_at + $this->server->connectionTimeout)) {
                return true;
            }
        }

        return false;
    }

    /**
     * whether the connection has processed too many requests
     * @return bool
     */
    public function tooManyRequests() {
        if($this->max_requests > 0 && $this->requests >= $this->max_requests) {
            return true;
        }

        return false;
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
        $this->server->handler->handleMessage($this);
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
