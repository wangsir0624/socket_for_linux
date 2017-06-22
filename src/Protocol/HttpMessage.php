<?php
namespace Wangjian\Socket\Protocol;

use ArrayAccess;

class HttpMessage implements ArrayAccess {
    /**
     * the http message headers
     * @var array
     */
    protected $headers;

    /**
     * the http message body
     * @var string
     */
    protected $body;

    /**
     * HttpMessage Constructor
     * @param array $headers
     * @param string $body
     */
    public function __construct($headers, $body) {
        $this->headers = $headers;
        $this->body = $body;
    }


    /**
     * get the http message headers
     * @return array
     */
    public function headers()  {
        return $this->headers;
    }

    /**
     * get/set the http message header
     * @param string  the header name
     * @param string  the header value
     * @return string
     */
    public function header($name, $value = null) {
        if(!is_null($value)) {
            $this->headers[$name] = $value;
        }

        return isset($this->headers[$name]) ? $this->headers[$name] : null;
    }

    /**
     * get/set the http message body
     * @param string $body
     * @return string
     */
    public function body($value = null) {
        if(!is_null($value)) {
            $this->body = $value;
        }

        return $this->body;
    }

    public function offsetExists($offset) {
        return isset($this->headers[$offset]);
    }

    public function offsetGet($offset) {
        return isset($this->headers[$offset]) ? $this->headers[$offset] : null;
    }

    public function offsetSet($offset, $value) {
        if (is_null($offset)) {
            $this->headers[] = $value;
        } else {
            $this->headers[$offset] = $value;
        }
    }

    public function offsetUnset($offset) {
        unset($this->headers[$offset]);
    }
}