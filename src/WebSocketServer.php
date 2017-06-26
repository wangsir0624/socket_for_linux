<?php
namespace Wangjian\Socket;

use Wangjian\Socket\Protocol\WebSocketProtocol;
use Wangjian\Socket\Module\MessageModule\WebSocketHandler;

class WebSocketServer extends WorkerServer {
    /**
     * Application protocol classname
     * @var string
     */
    public $protocol = WebSocketProtocol::class;

    public function __construct($ip, $port) {
        parent::__construct($ip, $port);
        $this->handler = new WebSocketHandler;
    }
}