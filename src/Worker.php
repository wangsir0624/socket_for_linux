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
use Wangjian\Socket\Connection\HttpHandler;
use Wangjian\Socket\Connection\WebSocketHandler;

class Worker {
    /**
     * the supported protocols
     * @const array
     */
    protected static $protocols = array(
        'ws' => WebSocketProtocol::class,
        'http' => HttpProtocol::class
    );

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
     * allowed http methods
     * @var array
     */
    protected $methods = ['GET', 'POST', 'HEAD', 'OPTIONS'];

    /**
     * MIME types
     * @var array
     */
    protected $mimes = [
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'dtd' => 'application/xml-dtd',
        'xhtml' => 'application/xhtml+xml',
        'bmp' => 'application/x-bmp',
        'html' => 'text/html',
        'php' => 'text/html',
        'htm' => 'text/html',
        'img' => 'application/x-img',
        'jpe' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff'
    ];

    /**
     * the virtual host configuration
     * @var array
     */
    public $hosts = array();

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
     * the accept timeout
     * @var int
     */
    public $connectionTimeout = 5;

    /**
     * Application protocol classname
     * @var string
     */
    public $protocol;

    /**
     * constructor
     * @param string uri the uri is like this: scheme//ip:port. for example, tcp://127.0.0.1:8000
     * @return ServerInterface
     */
    public function __construct($uri) {
        //parse the uri
        $schema = substr($uri, 0, strpos($uri, "://"));
        $address = substr($uri, strpos($uri, "://")+3);
        list($ip, $port) = explode(':', $address);

        //if the uri is incorrect, throw an InvalidArgumentException
        if(empty($schema) || empty($ip) || empty($port)) {
            throw new InvalidArgumentException('the argument is not correct.');
        }

        //choose the protocol according to the sheme
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

        //set the message handler
        switch($this->protocol) {
            case WebSocketProtocol::class:
                $connection->handler = new WebSocketHandler;
                break;
            case HttpProtocol::class:
                $connection->handler = new HttpHandler;
                break;
        }

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
     * set the accept timeout
     * @param $timeout  timeout in seconds
     */
    public function setConnectionTimeout($timeout) {
        $this->connectionTimeout = $timeout;
    }

    /**
     * add Mime types
     * @param array|string $ext
     * @param string $value
     */
    public function addMimeTypes($ext, $value = '') {
        if(is_array($ext)) {
            $this->mimes = array_merge($this->mimes, $ext);
        } else {
            $this->mimes[$ext] = $value;
        }
    }


    /**
     * get the Mime type
     * @param string $ext  the extension
     * @return string
     */
    public function getMimeType($ext) {
        if(empty($this->mimes[$ext])) {
            return 'application/octet-stream';
        }

        return $this->mimes[$ext];
    }

    /**
     * get the host configuration
     * @param string $host  the server name
     * @return array
     */
    public function getHostConfig($host) {
        if(empty($host)) {
            return isset($this->hosts['default']) ? $this->hosts['default'] : [];
        }

        return isset($this->hosts[$host]) ? $this->hosts[$host] : (isset($this->hosts['default']) ? $this->hosts['default'] : []);
    }

    /**
     * get the allowed http methods
     * @return array
     */
    public function allowedMethods() {
        return $this->methods;
    }
}