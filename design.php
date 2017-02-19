<?php
//服务器工厂类
class ServerFactory {
    //创建一个服务器
    public function server($uri) {}
}

//服务器类
//ServerInterface
//WebSocketServer HttpServer
class Server {
	private $stream;
	private $base;
	private $ip;
	private $port;
	private $connections;
	
	//这三个回调函数第一个参数均为$connection
	private $onConnect;
	private $onMessage;
	private $onClose;
	
	public function __construct($ip, $port) {}

	public function listen() {}

	public function shutdown() {}

	public function handleConnection() {}

	public function createConnection() {}
}

//连接类
//ConnectionInterface
class Connection {
	private $server;
	private $stream;
	
	public function __construct($server) {}

	public function sendMsg();

	public function readMsg();

	public function close();

	public function handleMessage() {}
}

//协议类
//ProtocolInterface
//HttpProtocol
class Protocol {
	public static function parseRequest($request) {}
	
	public static function parseResponse($response) {}

	public static function toRequest($arrayRequest) {}

	public static function toResponse($arrayResponse) {}
}