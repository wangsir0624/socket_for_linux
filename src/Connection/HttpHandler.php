<?php
namespace Wangjian\Socket\Connection;

use Wangjian\Socket\Exception\BadRequestException;
use Wangjian\Socket\Protocol\HttpProtocol;
use Wangjian\Socket\Protocol\HttpMessage;
use Wangjian\Socket\EventLoop\EventLoopInterface;

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
                    case 'GET':
                        //get the host configuration
                        $host_config = $connection->server->getHostConfig($http_message['Host']);
                        if(empty($host_config)) {
                            echo "You haven't configure the hosts yet.\r\n";
                            return;
                        }

                        //get the request file path
                        $uri = $http_message['Uri'];
                        @list($request_file, $query_string) = explode('?', $uri);
                        $request_file = rtrim($host_config['root'], '/').'/'.ltrim($request_file, '/.');
                        
                        //if the file does not exists, send 404 not found respond
                        if(!file_exists($request_file)) {
                            $body = "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\"><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL $uri was not found on this server.</p></body></html>";
                            $notFoundResponse = new HttpMessage([
                                'Code' => '404',
                                'Status' => HttpProtocol::$status['404'],
                                'Version' => $http_message['Version'],
                                'Content-Type' => 'text/html',
                                'Content-Length' => strlen($body),
                                'Date' => date('D, d m Y H:i:s e')
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
                                        'Content-Length' => strlen($body),
                                        'Date' => date('D, d m Y H:i:s e')
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
                                    'Content-Length' => strlen($body),
                                    'Date' => date('D, d m Y H:i:s e')
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
                                    'Content-Length' => filesize($request_file),
                                    'Last-Modified' => date('D, d m Y H:i:s e', filemtime($request_file)),
                                    'Date' => date('D, d m Y H:i:s e'),
                                    'Accept-Ranges' => 'bytes'
                                ], '');

                                //if this is a range request
                                $start = 0;
                                if(!empty($http_message['Range'])) {
                                    if(preg_match('/^bytes=(\d*?)-(\d*?)$/i', $http_message['Range'], $matches)) {
                                        $start = $matches[1];
                                        $end = $matches[2];

                                        //check whether the range is correct
                                        if(!empty($end) && $end > filesize($request_file)) {
                                            //if the range is incorrect, return 416 response
                                            $body = <<<EOF
<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
<html><head>
<title>416 Requested range not satisfiable</title>
</head><body>
<h1>Requested range not satisfiable</h1>
</body></html>
EOF;
                                            $rangeNotSatisfiableResponse = new HttpMessage([
                                                'Code' => '416',
                                                'Status' => HttpProtocol::$status['416'],
                                                'Version' => $http_message['Version'],
                                                'Content-Type' => 'text/html',
                                                'Content-Length' => strlen($body),
                                                'Date' => date('D, d m Y H:i:s e')
                                            ], $body);
                                            $connection->sendString($rangeNotSatisfiableResponse);

                                            return;
                                        }

                                        $response['Code'] = '206';
                                        $response['Status'] = HttpProtocol::$status['206'];
                                        $response['Content-Length'] = filesize($request_file) - $start;
                                        $response['Content-Range'] = "bytes $start-$end/" . filesize($request_file);
                                    }
                                }
                                $connection->sendString($response);

                                //send the body
                                $fd = fopen($request_file, 'rb');
                                if (!$fd) {
                                    $connection->close();
                                    return;
                                }
                                fseek($fd, $start);
                                $connection->sendFile($fd);
                            }
                        }
                        break;
                    case 'POST':
                        break;
                    case 'HEAD':
                        break;
                    case 'OPTIONS':
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