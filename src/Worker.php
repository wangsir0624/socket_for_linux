<?php
namespace Wangjian\Socket;

use Wangjian\Socket\Connection\ConnectionInterface;
use Wangjian\Socket\EventLoop\EventLoopInterface;
use Wangjian\Socket\EventLoop\StreamSelectLoop;
use RuntimeException;
use Wangjian\Socket\Connection\Connection;
use SplObjectStorage;
use Wangjian\Socket\EventLoop\EventLoopFactory;
use Wangjian\Socket\Protocol\WebSocketProtocol;
use Wangjian\Socket\Protocol\HttpProtocol;
use Wangjian\Socket\Module\MessageModule\MessageHandler;

class Worker {
    /**
     * the server socket stream
     * @var resource
     */
    public $stream;

    /**
     * the server IP
     * @var string
     */
    private $ip;

    /**
     * the server port
     * @var int
     */
    private $port;

    /**
     * the callback when the server accept the client connections
     * function($connection) {}
     * @var callable
     */
    public $onConnection;

    /**
     * the callback when the server receives client message
     * function($connection, $message) {}
     * @var callable
     */
    public $onMessage;

    /**
     * the callback when the connection is closed
     * function($connection) {}
     * @var callable
     */
    public $onClose;

    /**
     * the callback when an error occurs
     * function($connection, $error) {}
     * @var callable
     */
    public $onError;

    /**
     * the connection collections
     * @var SplObjectStorage
     */
    public $connections;

    /**
     * the event loop
     * @var EventLoopInterface
     */
    public $loop;

    /**
     * the connection timeout
     * @var int
     */
    public $connectionTimeout = 60;

    /**
     * the message handler
     * @var MessageHandler
     */
    public $handler;


    /**
     * constructor
     * @param string $ip
     * @param int port
     * @return ServerInterface
     */
    public function __construct($ip, $port) {
        $this->ip = $ip;
        $this->port = $port;
        $this->loop = EventLoopFactory::createLoop();
        $this->connections = new SplObjectStorage();
    }

    /**
     * create the server socket stream
     */
    public function createSocket() {
        $stream = @stream_socket_server("tcp://$this->ip:$this->port", $errno, $errstr);
        if(!$stream) {
            throw new RuntimeException('error('.$errno.'): '.$errstr);
        }

        $this->stream = $stream;
        stream_set_blocking($this->stream, 0);
    }

    /**
     * close the server socket stream
     */
    public function closeSocket() {
        if(is_resource($this>$this->stream)) {
            fclose($this->stream);
        }
    }

    /**
     * listening
     */
    public function listen() {
        $this->loop->add($this->stream, EventLoopInterface::EV_READ, array($this, 'handleConnection'));
        $this->loop->add(5, EventLoopInterface::EV_TIMER, array($this, 'clearTimedOutConnections'));
        $this->loop->run();
    }

    /**
     * handle the connection request from client
     */
    public function handleConnection() {
        try {
            $connection = $this->createConnection();
        } catch(\Exception $e) {
            return;
        }
        $this->sm->increment('current_connections');
        $this->sm->increment('total_connections');

        $this->connections->attach($connection);
        stream_set_blocking($connection->stream, 0);

        $this->loop->add($connection->stream, EventLoopInterface::EV_READ, array($connection, 'handleMessage'));
    }

    /**
     * create a connection instance
     * @return ConnectionInterface
     */
    private function createConnection() {
        return new Connection($this);
    }

    /**
     * clear timed out connections
     */
    public function clearTimedOutConnections() {
        foreach($this->connections as $connection) {
            if(($connection->timedOut() || $connection->tooManyRequests()) && $connection->isSendBufferEmpty()) {
                $connection->close();
            }
        }
    }
}