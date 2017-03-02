<?php
namespace Protocol;

use Connection\ConnectionInterface;

class WebSocketProtocol implements ProtocolInterface {
    /**
     * minimun bytes of websocket frame header
     * @const
     */
    const MIN_HEAD_LEN = 2;

    /**
     * opcode value which means the frame is continuing
     * @const
     */
    const WEBSOCKET_TYPE_CONTINUE = 0x0;

    /**
     * opcode value which means the frame is text encoded
     * @const
     */
    const WEBSOCKET_TYPE_TEXT = 0x1;

    /**
     * opcode value which means the frame is binary encoded
     * @const
     */
    const WEBSOCKET_TYPE_BINARY = 0x2;

    /**
     * opcode value which means the websocket connection is closed
     * @const
     */
    const WEBSOCKET_TYPE_CLOSE = 0x8;

    /**
     * opcode value of the ping frame
     * @const
     */
    const WEBSOCKET_TYPE_PING = 0x9;

    /**
     * opcode value of the pong frame
     * @const
     */
    const WEBSOCKET_TYPE_PONG = 0xa;

    /**
     * get the websocket frame length.
     * @param $buffer
     * @param ConnectionInterface $connection
     * @return int return the frame length when the buffer is ready. Notice: when the buffer is not ready and should wait for more data, returns 0
     */
    public static function input($buffer, ConnectionInterface $connection) {
        //if the connection is not handshaked, do the handshake
        if(empty($connection->handshaked)) {
            //if the handshake if failed, wait for more data
            if(!self::handshake($buffer, $connection)) {
                return 0;
            }

            $buffer = $connection->recv_buffer;
        }

        //wait for more data
        if(strlen($buffer) < self::MIN_HEAD_LEN) {
            return 0;
        }

        if($connection->current_websocket_frame_length > 0) {
            if($connection->current_websocket_frame_length > strlen($buffer)) {
                return 0;
            }

            return $connection->current_websocket_frame_length;
        }

        //parse the websocket protocol frame
        $data_len = ord($buffer{1}) & 127;
        $is_mask = ord($buffer{1}) >> 7;
        $is_fin_frame = ord($buffer{0}) >> 7;
        $opcode = ord($buffer{0}) & 0b00001111;
        $head_len = self::MIN_HEAD_LEN;

        //if the data is masked, the frame head contains 4 bytes for the mask key
        if($is_mask) {
            $head_len += 4;
        }

        /**
         * if the data_len is 126, the frame head container 2 more bytes for data lenth. and when the data_len is 127, the head contains 8 bytes.
         */
        if($data_len == 126) {
            $head_len += 2;

            if(strlen($buffer) < $head_len) {
                return 0;
            }

            $pack = unpack('n/ntotal_len', $buffer);
            $data_len = $pack['total_len'];
        } else if($data_len == 127) {
            $head_len += 8;

            if(strlen($buffer) < $head_len) {
                return 0;
            }

            $pack = unpack('n/N2c', $buffer);
            $data_len = $pack['c1']*4294967296 + $pack['c2'];
        }

        switch($opcode) {
            case self::WEBSOCKET_TYPE_CONTINUE:
                break;
            case self::WEBSOCKET_TYPE_TEXT:
                break;
            case self::WEBSOCKET_TYPE_BINARY:
                break;
            case self::WEBSOCKET_TYPE_CLOSE:
                //the connection is closed by the websocket client
                if(is_callable($connection->server->onClose)) {
                    call_user_func($connection->server->onClose, $connection);
                }

                $connection->close();
                break;
            case self::WEBSOCKET_TYPE_PING:
                break;
            case self::WEBSOCKET_TYPE_PONG:
                break;
            default:
                //the opcode is invalid
                if(is_callable($connection->server->onError)) {
                    call_user_func($connection->server->onError, $connection, 'error oppcode.');
                }

                $connection->close();
                break;
        }

        //TODO: 如果这一帧不是结束帧

        $current_frame_length = $head_len + $data_len;
        //if the buffer lenth is shorten than the current frame lenth, wait for more data
        if($current_frame_length > strlen($buffer)) {
            $connection->current_websocket_frame_length = $current_frame_length;

            return 0;
        }

        return $current_frame_length;
    }

    /**
     * websocket encode
     * @param $buffer
     * @param ConnectionInterface $connection
     * @return string  returns the encoded buffer
     */
    public static function encode($buffer, ConnectionInterface $connection) {
        $len = strlen($buffer);

        $first_byte = chr(self::WEBSOCKET_TYPE_TEXT | 0b10000000);

        if($len <= 125) {
            $data = $first_byte.chr($len).$buffer;
        } else if($len <= 65535) {
            $data = $first_byte.chr(126).pack('n', $len).$buffer;
        } else {
            $data = $first_byte.char(127).pack('xxxxN', $len).$buffer;
        }

        return $data;
    }

    /**
     * websocket decode
     * @param $buffer
     * @param ConnectionInterface $connection
     * @return string  returns the original data
     */
    public static function decode($buffer, ConnectionInterface $connection) {
        $data_len = ord($buffer{1}) & 127;
        $is_mask = ord($buffer{1}) >> 7;

        if($data_len == 126) {
            $extra_payload_length = 2;
            $mask_key = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else if($data_len == 127) {
            $extra_payload_length = 8;
            $mask_key = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            $extra_payload_length = 0;
            $mask_key = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }

        $original = '';
        if($is_mask) {
            $mask_key = substr($buffer, self::MIN_HEAD_LEN + $extra_payload_length, 4);
            $data = substr($buffer, self::MIN_HEAD_LEN + $extra_payload_length + 4);
            for($i = 0; $i < strlen($data); $i++) {
                $original .= $data{$i} ^ $mask_key[$i % 4];
            }
        } else {
            $original = substr($buffer, self::MIN_HEAD_LEN + $extra_payload_length);
        }

        if($original) {
            if (is_callable($connection->server->onMessage)) {
                call_user_func($connection->server->onMessage, $connection, $original);
            }
        }

        return $original;
    }

    /**
     * do the handshake
     * @param $buffer
     * @param ConnectionInterface $connection
     * @return bool
     */
    public static function handshake($buffer, ConnectionInterface $connection) {
        $connection->handshaked = false;

        if(strpos($buffer, 'GET') === 0) {
            $header_end_pos = strpos($buffer, "\r\n\r\n");

            if($header_end_pos === false) {
                return false;
            }

            $header_length = $header_end_pos + 4;

            if(!preg_match("/Sec-Websocket-Key: *(.*?)\r\n/i", $buffer, $matches)) {
                $connection->send("HTTP/1.1 400 Bad Request\r\n\r\nThere is not Sec-Websocket-Key header in the request. Websocket handshake failed.", true);

                if(is_callable($connection->server->onError)) {
                    call_user_func($connection->server->onError, $connection, 'There is not Sec-Websocket-Key header in the request. Websocket handshake failed.');
                }

                $connection->close();

                return $connection->handshaked;
            }

            $sec_websocket_accept = base64_encode(sha1($matches[1] . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));

            $handshake_response = "HTTP/1.1 101 Switching Protocol\r\n";
            $handshake_response .= "Upgrade: websocket\r\n";
            $handshake_response .= "Connection: Upgrade\r\n";
            $handshake_response .= "Sec-Websocket-Accept: $sec_websocket_accept\r\n";
            $handshake_response .= "\r\n";

            if($connection->send($handshake_response, true)) {
                $connection->handshaked = true;
                $connection->current_websocket_frame_length = 0;

                $header_string = substr($connection->recv_buffer, 0, $header_length);
                $connection->recv_buffer = substr($connection->recv_buffer, $header_length);

                self::parseHttpHeaders($header_string);

                if(is_callable($connection->server->onConnection)) {
                    call_user_func($connection->server->onConnection, $connection);
                }

                return $connection->handshaked;
            }


            return $connection->handshaked;
        }

        $connection->send("HTTP/1.1 400 Bad Request\r\n\r\nInvalid handshake data for websocket.", true);

        if(is_callable($connection->server->onError)) {
            call_user_func($connection->server->onError, $connection, 'Invalid handshake data for websocket.');
        }

        $connection->close();

        return $connection->handshaked;
    }

    /**
     * parse the http headers
     * @param $header_string
     */
    public static function parseHttpHeaders($header_string) {

    }
}