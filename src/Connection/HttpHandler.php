<?php
namespace Wangjian\Socket\Connection;

use Wangjian\Socket\Exception\BadRequestException;
use Wangjian\Socket\Protocol\HttpProtocol;
use Wangjian\Socket\Protocol\HttpMessage;

class HttpHandler extends MessageHandler {
    public function handleMessage(ConnectionInterface $connection) {
        $buffer = fread($connection->stream, $connection->recv_buffer_size);

        $connection->recv_buffer .= $buffer;

        $protocol = $connection->server->protocol;

        try {
            $connection->current_package_size = $protocol::input($connection->recv_buffer, $connection);

            if ($connection->current_package_size != 0) {
                $buffer = substr($connection->recv_buffer, 0, $connection->current_package_size);
                $connection->recv_buffer = substr($connection->recv_buffer, $connection->current_package_size);
                $connection->current_package_size = 0;

                $http_message = $protocol::decode($buffer, $connection);

                //handle the request
                switch($http_message['Method']) {
                    case 'GET':
                        //get the host configuration
                        $host_config = $connection->server->getHostConfig($http_message['Host']);
                        if(empty($host_config)) {
                            echo "You haven't configure the hosts yet.\r\n";
                            return;
                        }

                        //get the request file path
                        $uri = $http_message['Uri'];
                        list($request_file, $query_string) = explode('?', $uri);
                        $request_file = rtrim($host_config['root'], '/').'/'.ltrim($request_file, '/.');
                        
                        //if the file does not exists, send 404 not found respond
                        if(!file_exists($request_file)) {
                            $body = "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\"><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL $uri was not found on this server.</p></body></html>";
                            $notFoundResponse = new HttpMessage([
                                'Code' => '404',
                                'Status' => HttpProtocol::$status['404'],
                                'Version' => $http_message['Version'],
                                'Content-Type' => 'text/html',
                                'Content-Length' => strlen($body)
                            ], $body);
                            $connection->sendString($notFoundResponse);
                        } else {
                            if(is_dir($request_file)) {
                                $index = @$host_config['index'];
                                $indexes = explode(' ', $index);

                                foreach($indexes as $index) {
                                    $tmp_file = $request_file.'/'.$index;
                                    if(is_file($tmp_file)) {
                                        break;
                                    }
                                }

                                if(is_file($tmp_file)) {
                                    $request_file = $tmp_file;
                                } else {
                                    $body = <<<EOF
<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
<html><head>
<title>403 Forbidden</title>
</head><body>
<h1>Forbidden</h1>
</body></html>
EOF;
                                    $forbiddenResponse = new HttpMessage([
                                        'Code' => '403',
                                        'Status' => HttpProtocol::$status['403'],
                                        'Version' => $http_message['Version'],
                                        'Content-Type' => 'text/html',
                                        'Content-Length' => strlen($body)
                                    ], $body);
                                    $connection->sendString($forbiddenResponse);
                                    return;
                                }
                            }

                            if(!is_readable($request_file)) {
                                $body = <<<EOF
<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
<html><head>
<title>403 Forbidden</title>
</head><body>
<h1>Forbidden</h1>
</body></html>
EOF;
                                $methodNotAllowedResponse = new HttpMessage([
                                    'Code' => '403',
                                    'Status' => HttpProtocol::$status['403'],
                                    'Version' => $http_message['Version'],
                                    'Content-Type' => 'text/html',
                                    'Content-Length' => strlen($body)
                                ], $body);
                                $connection->sendString($methodNotAllowedResponse);
                            } else {
                                $ext = pathinfo($request_file, PATHINFO_EXTENSION);
                                //get the mime type
                                $mime = $connection->server->getMimeType($ext);

                                //send the header first
                                $response = new HttpMessage([
                                    'Code' => '200',
                                    'Status' => HttpProtocol::$status['200'],
                                    'Version' => $http_message['Version'],
                                    'Content-Type' => $mime,
                                    'Content-Length' => filesize($request_file)
                                ], '');
                                $connection->send($response);

                                //send the body
                                $fd = fopen($request_file, 'rb');
                                if (!$fd) {
                                    $connection->close();
                                    return;
                                }
                                $connection->sendFile($fd);
                            }
                        }
                        break;
                    case 'POST':
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
                        'Content-Length' => strlen($body)
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
                'Version' => $http_message['Version'],
                'Content-Type' => 'text/html',
                'Content-Length' => strlen($body)
            ], $body);
            $connection->sendString($badRequestResponse);
            $connection->close();
        }
    }
}