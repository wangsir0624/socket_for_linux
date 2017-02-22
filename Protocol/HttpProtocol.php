<?php
namespace Protocol;

class HttpProtocol implements ProtocolInterface {
    public static $statusTexts = array(
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',            // RFC2518
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',          // RFC4918
        208 => 'Already Reported',      // RFC5842
        226 => 'IM Used',               // RFC3229
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => 'Reserved',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',    // RFC-reschke-http-status-308-07
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Requested Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',                                               // RFC2324
        422 => 'Unprocessable Entity',                                        // RFC4918
        423 => 'Locked',                                                      // RFC4918
        424 => 'Failed Dependency',                                           // RFC4918
        425 => 'Reserved for WebDAV advanced collections expired proposal',   // RFC2817
        426 => 'Upgrade Required',                                            // RFC2817
        428 => 'Precondition Required',                                       // RFC6585
        429 => 'Too Many Requests',                                           // RFC6585
        431 => 'Request Header Fields Too Large',                             // RFC6585
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates (Experimental)',                      // RFC2295
        507 => 'Insufficient Storage',                                        // RFC4918
        508 => 'Loop Detected',                                               // RFC5842
        510 => 'Not Extended',                                                // RFC2774
        511 => 'Network Authentication Required',                             // RFC6585
    );

    /**
     * 将协议的请求数据解析成数组形式
     * @param string $request
     * @return array
     */
    public static function parseRequest($request) {
        list($raw_headers, $body) = explode("\r\n\r\n", $request);
        $raw_headers = explode("\r\n", $raw_headers);
        $request_line = array_shift($raw_headers);

        list($method, $uri, $version) = explode(' ', $request_line);

        foreach($raw_headers as $header) {
            list($key, $value) = explode(': ', $header);
            $headers[$key] = $value;
        }

        return compact(array('method', 'uri', 'version', 'headers', 'body'));
    }

    /**
     * 将协议的响应数据解析成数组形式
     * @param string $response
     * @return array
     */
    public static function parseResponse($response) {
        list($raw_headers, $body) = explode("\r\n\r\n", $response);
        $raw_headers = explode("\r\n", $raw_headers);
        $response_line = array_shift($raw_headers);

        list($version, $code, $status) = explode(' ', $response_line);

        foreach($raw_headers as $header) {
            list($key, $value) = explode(': ', $header);
            $headers[$key] = $value;
        }

        return compact(array('code', 'status', 'version', 'headers', 'body'));
    }

    /**
     * 将数组形式的请求转换成字符串供套接字发送
     * @param array $arrayRequest
     * @return string
     */
    public static function toRequestString($arrayRequest) {
        if(empty($arrayRequest['method'])) {
            $arrayRequest['method'] = 'GET';
        }

        if(empty($arrayRequest['version'])) {
            $arrayRequest['version'] = 'HTTP/1.1';
        }

        $request = "$arrayRequest[method] $arrayRequest[uri] $arrayRequest[version]\r\n";
        foreach($arrayRequest['headers'] as $key => $value) {
            $request .= "$key: $value\r\n";
        }
        $request .= "\r\n";
        $request .= $arrayRequest['body'];

        return $request;
    }

    /**
     * 将数组形式的响应转换成字符串供套接字发送
     * @param array $arrayResponse
     * @return string
     */
    public static function toResponseString($arrayResponse) {
        if(empty($arrayResponse['version'])) {
            $arrayResponse['version'] = 'HTTP/1.1';
        }

        if(empty($arrayResponse['code'])) {
            $arrayResponse['code'] = 200;
        }

        if(empty($arrayResponse['status'])) {
            $arrayResponse['status'] = self::$statusTexts[$arrayResponse['code']];
        }

        $response = "$arrayResponse[version] $arrayResponse[code] $arrayResponse[status]\r\n";
        foreach($arrayResponse['headers'] as $key => $value) {
            $response .= "$key: $value\r\n";
        }
        $response .= "\r\n";
        $response .= $arrayResponse['body'];

        return $response;
    }
}