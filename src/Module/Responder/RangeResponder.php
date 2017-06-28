<?php
namespace Wangjian\Socket\Module\Responder;

use Wangjian\Socket\Protocol\HttpProtocol;
use Wangjian\Socket\Protocol\HttpMessage;
use Wangjian\Socket\Connection\ConnectionInterface;

class RangeResponder extends AbstractResponder {
    public function respond(HttpMessage $message, ConnectionInterface $connection, $onlyHeader = false) {
        //if the Range header does not exists, pass it to the next responder
        if(empty($message['Range'])) {
            return $this->next($message, $connection, $onlyHeader);
        }

        //get the host configuration
        $host_config = $connection->server->getHostConfig($message['Host']);
        if(empty($host_config)) {
            echo "You haven't configure the hosts yet.\r\n";
            return;
        }

        //get the request file path
        $uri = $message['Uri'];
        @list($request_file, $query_string) = explode('?', $uri);
        $request_file = rtrim($host_config['root'], '/').'/'.ltrim($request_file, '/.');

        //if the file does not exists, send 404 not found respond
        if(!file_exists($request_file)) {
            $body = $message['Method'] == 'HEAD' ? '' : "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\"><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL $uri was not found on this server.</p></body></html>";
            $notFoundResponse = new HttpMessage([
                'Code' => '404',
                'Status' => HttpProtocol::$status['404'],
                'Version' => $message['Version'],
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
                    $body = $message['Method'] == 'HEAD' ? '' : <<<EOF
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
                        'Version' => $message['Version'],
                        'Content-Type' => 'text/html',
                        'Content-Length' => strlen($body),
                        'Date' => date('D, d m Y H:i:s e')
                    ], $body);
                    $connection->sendString($forbiddenResponse);
                    return;
                }
            }

            if(!is_readable($request_file)) {
                $body = $message['Method'] == 'HEAD' ? '' : <<<EOF
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
                    'Version' => $message['Version'],
                    'Content-Type' => 'text/html',
                    'Content-Length' => strlen($body),
                    'Date' => date('D, d m Y H:i:s e')
                ], $body);
                $connection->sendString($methodNotAllowedResponse);
            } else {
                $ext = pathinfo($request_file, PATHINFO_EXTENSION);
                //get the mime type
                $mime = $connection->server->getMimeType($ext);

                if(preg_match('/^bytes=(\d*?)-(\d*?)$/i', $message['Range'], $matches)) {
                    $start = $matches[1];
                    $end = $matches[2];

                    //check whether the range is correct
                    if(!empty($end) && $end > filesize($request_file)) {
                        //if the range is incorrect, return 416 response
                        $body = $message['Method'] == 'HEAD' ? '' : <<<EOF
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
                            'Version' => $message['Version'],
                            'Content-Type' => 'text/html',
                            'Content-Length' => strlen($body),
                            'Date' => date('D, d m Y H:i:s e')
                        ], $body);
                        $connection->sendString($rangeNotSatisfiableResponse);

                        return;
                    }

                    //send the header first
                    $response = new HttpMessage([
                        'Code' => '206',
                        'Status' => HttpProtocol::$status['206'],
                        'Version' => $message['Version'],
                        'Content-Type' => $mime,
                        'Content-Length' => filesize($request_file) - $start,
                        'Last-Modified' => date('D, d m Y H:i:s e', filemtime($request_file)),
                        'Date' => date('D, d m Y H:i:s e'),
                        'Content-Range' => "bytes $start-$end/" . filesize($request_file)
                    ], '');
                }
                $connection->sendString($response);

                if($onlyHeader == false) {
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
        }
    }
}