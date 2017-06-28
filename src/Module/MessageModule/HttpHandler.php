<?php
namespace Wangjian\Socket\Module\MessageModule;

use Wangjian\Socket\Exception\BadRequestException;
use Wangjian\Socket\Protocol\HttpProtocol;
use Wangjian\Socket\Protocol\HttpMessage;
use Wangjian\Socket\EventLoop\EventLoopInterface;
use Wangjian\Socket\Connection\ConnectionInterface;
use Wangjian\Socket\Module\Responder\FileResponder;
use Wangjian\Socket\Module\Responder\RangeResponder;

class HttpHandler extends MessageHandler {
    public function handleMessage(ConnectionInterface $connection) {
        $buffer = fread($connection->stream, $connection->recv_buffer_size);

        $connection->recv_buffer .= $buffer;

        $protocol = $connection->server->protocol;

        try {
            $connection->current_package_size = $protocol::input($connection->recv_buffer, $connection);

            if ($connection->current_package_size != 0) {
                //update the requests
                $connection->requests++;

                $buffer = substr($connection->recv_buffer, 0, $connection->current_package_size);
                $connection->recv_buffer = substr($connection->recv_buffer, $connection->current_package_size);
                $connection->current_package_size = 0;

                $http_message = $protocol::decode($buffer, $connection);

                //keep-alive
                if($http_message['connection'] == 'close') {
                    $connection->timeout = time();
                } else {
                    $keepalive = $http_message['Keep-Alive'];
                    if(!empty($keepalive)) {
                        $keepalives = [];
                        foreach(explode(', ', $keepalive) as $item) {
                            list($key, $value) = explode('=', $item);
                            $keepalives[$key] = $value;
                        }
                    }

                    if(!empty($keepalives['timeout'])) {
                        $connection->timeout = max($connection->timeout, $keepalives['timeout'] + time());
                    }

                    if(!empty($keepalives['max'])) {
                        $connection->max_requests = max($connection->max_requests, $keepalives['max']);
                    }
                }

                //check whether the method is allowed
                if(!in_array($http_message['Method'], $connection->server->allowedMethods())) {
                    $body = <<<EOF
<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
<html><head>
<title>405 Method Not Allowed</title>
</head><body>
<h1>Method Not Allowed</h1>
</body></html>
EOF;
                    $methodNotAllowedResponse = new HttpMessage([
                        'Code' => '405',
                        'Status' => HttpProtocol::$status['405'],
                        'Version' => $http_message['Version'],
                        'Content-Type' => 'text/html',
                        'Content-Length' => strlen($body),
                        'Date' => date('D, d m Y H:i:s e')
                    ], $body);
                    $connection->sendString($methodNotAllowedResponse);
                    return;
                }

                //handle the request
                switch($http_message['Method']) {
                    case 'HEAD':
                    case 'GET':
                        $rangeResponder = new RangeResponder;
                        $fileResponder = new FileResponder;
                        $rangeResponder->setNextResponder($fileResponder);

                        $chainOfResponder = $rangeResponder;
                        $onlyHeader = false;
                        //if the request method is head, respond header only
                        if($http_message['Method'] == 'HEAD') {
                            $onlyHeader = true;
                        }
                        $chainOfResponder->respond($http_message, $connection, $onlyHeader);
                        break;
                    case 'POST':
                        break;
                    case 'OPTIONS':
                        $optionsResponse = new HttpMessage([
                            'Code' => '200',
                            'Status' => HttpProtocol::$status['200'],
                            'Version' => $http_message['Version'],
                            'Date' => date('D, d m Y H:i:s e'),
                            'Allow' => implode(', ', $connection->server->allowedMethods())
                        ], '');
                        $connection->sendString($optionsResponse);
                        break;
                    default:
                        $body = <<<EOF
<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
<html><head>
<title>405 Method Not Allowed</title>
</head><body>
<h1>Method Not Allowed</h1>
</body></html>
EOF;
                    $methodNotAllowedResponse = new HttpMessage([
                        'Code' => '405',
                        'Status' => HttpProtocol::$status['405'],
                        'Version' => $http_message['Version'],
                        'Content-Type' => 'text/html',
                        'Content-Length' => strlen($body),
                        'Date' => date('D, d m Y H:i:s e')
                    ], $body);
                    $connection->sendString($methodNotAllowedResponse);
                }

                if (!empty($connection->recv_buffer)) {
                    call_user_func(array($connection, 'handleMessage'));
                }
            }
        } catch(BadRequestException $e) {
            $body = <<<EOF
<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
<html><head>
<title>400 Bad Request</title>
</head><body>
<h1>Bad Request</h1>
</body></html>
EOF;
            $badRequestResponse = new HttpMessage([
                'Code' => '400',
                'Status' => HttpProtocol::$status['400'],
                'Version' => 'HTTP/1.1',
                'Content-Type' => 'text/html',
                'Content-Length' => strlen($body),
                'Date' => date('D, d m Y H:i:s e')
            ], $body);
            $connection->sendString($badRequestResponse);
            $connection->close();
        }
    }
}