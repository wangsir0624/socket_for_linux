<?php
namespace Wangjian\Socket\Protocol;

use Wangjian\Socket\Connection\ConnectionInterface;

class WebSocketProtocol implements ProtocolInterface {
    /**
     * minimun bytes of websocket frame header
     * @const
     */
    const MIN_HEAD_LEN = 2;

    /**
     * maximun bytes of websocket frame header
     */
    const MAX_HEAD_LEN = 14;

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

        //because some websocket requests may consist of more than one frame, we can get the total length by recursive
        $buffer = substr($buffer, $connection->tmp_all_frame_len);

        //if the connection read buffer is shorter than the websocket minimun header length, wait for more data
        if(strlen($buffer) < self::MIN_HEAD_LEN) {
            return 0;
        }

        //parse the websocket frame
        $data_len = ord($buffer{1}) & 127;
        $is_mask = ord($buffer{1}) >> 7;
        $is_fin_frame = ord($buffer{0}) >> 7;
        $head_len = self::MIN_HEAD_LEN;

        //if the data is masked, the frame head contains 4 bytes for the mask key
        if($is_mask) {
            $head_len += 4;
        }

        //if the data_len is 126, the frame head container 2 more bytes for data length. and when the data_len is 127, the head contains 8 more bytes
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

        $all_frame_len = $connection->tmp_all_frame_len +  $head_len + $data_len;

        if($all_frame_len > strlen($connection->recv_buffer)) {
            return 0;
        }

        if($all_frame_len + self::MAX_HEAD_LEN >= $connection->recv_buffer_size) {
            if(is_callable($connection->server->onError)) {
                call_user_func($connection->server->onError, $connection, 'the websocket data length exceeds the maximun limit.');
            }

            $connection->close();

            return 0;
        }

        //if the websocket is made of more than one frame, we must get the total length recursively
        if(!$is_fin_frame) {
            $connection->tmp_all_frame_len = $all_frame_len;
            return self::input($connection->recv_buffer, $connection);
        }

        $connection->tmp_all_frame_len = 0;
        return $all_frame_len;
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
        $buffer = substr($buffer, $connection->tmp_frame_len);

        $data_len = ord($buffer{1}) & 127;
        $is_mask = ord($buffer{1}) >> 7;
        $is_fin_frame = ord($buffer{0}) >> 7;
        $opcode = ord($buffer{0}) & 0b00001111;

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

                return 0;
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

                return 0;
                break;
        }

        if($data_len == 126) {
            $extra_payload_length = 2;
            $pack = unpack('n/ntotal_len', $buffer);
            $data_len = $pack['total_len'];
        } else if($data_len == 127) {
            $extra_payload_length = 8;
            $pack = unpack('n/N2c', $buffer);
            $data_len = $pack['c1']*4294967296 + $pack['c2'];
        } else {
            $extra_payload_length = 0;
        }

        $original = $connection->tmp_data;
        if($is_mask) {
            $mask_key = substr($buffer, self::MIN_HEAD_LEN + $extra_payload_length, 4);
            $data = substr($buffer, self::MIN_HEAD_LEN + $extra_payload_length + 4, $data_len);
            for($i = 0; $i < strlen($data); $i++) {
                $original .= $data{$i} ^ $mask_key[$i % 4];
            }
        } else {
            $original .= substr($buffer, self::MIN_HEAD_LEN + $extra_payload_length, $data_len);
        }

        //if the websocket request is made of more than one frame, get the request data recursively
        if(!$is_fin_frame) {
            $connection->tmp_data .= $original;
            $connection->tmp_frame_len += $data_len;

            self::decode($connection->recv_buffer, $connection);
        }

        $connection->tmp_data .= '';
        $connection->tmp_frame_len += 0;

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

        //if the handshake request is invalid, handshake fails and return a 400 response to the client
        if(!self::validateHandshakeRequest($buffer)) {
            $connection->send("HTTP/1.1 400 Bad Request\r\n\r\nInvalid handshake data for websocket.", true);

            if(is_callable($connection->server->onError)) {
                call_user_func($connection->server->onError, $connection, 'Invalid handshake data for websocket.');
            }

            $connection->server->sm->increment('failed_connections');

            $connection->close();

            return false;
        }

        $header_end_pos = strpos($buffer, "\r\n\r\n");

        //if the handshake data is not done, wait for more data
        if($header_end_pos === false) {
            return false;
        }

        $header_length = $header_end_pos + 4;

        //calculate the Sec-Websocket-Accept response header based on the Sec-Websocket-Key request header
        preg_match("/Sec-Websocket-Key: *(.*?)\r\n/i", $buffer, $matches);
        $sec_websocket_accept = base64_encode(sha1($matches[1] . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));

        //return 101 response and upgrade the client protocol to websocket
        $handshake_response = "HTTP/1.1 101 Switching Protocol\r\n";
        $handshake_response .= "Upgrade: websocket\r\n";
        $handshake_response .= "Connection: Upgrade\r\n";
        $handshake_response .= "Sec-Websocket-Accept: $sec_websocket_accept\r\n";
        $handshake_response .= "\r\n";

        if($connection->send($handshake_response, true)) {
            $connection->handshaked = true;
            $connection->tmp_all_frame_len = 0;
            $connection->tmp_frame_len = 0;
            $connection->tmp_data = '';

            $header_string = substr($connection->recv_buffer, 0, $header_length);
            $connection->recv_buffer = substr($connection->recv_buffer, $header_length);

            if(is_callable($connection->server->onConnection)) {
                call_user_func($connection->server->onConnection, $connection);
            }

            return true;
        } else {
            return false;
        }
    }

    public static function validateHandshakeRequest($buffer) {
        if(!preg_match("/^GET .* HTTP\/1.[01]\r\n(?:.+\: .*\r\n)*\r\n/mi", $buffer)) {
            return false;
        } else {
            if(!preg_match("/Sec-Websocket-Key: .*\r\n/i", $buffer)) {
                return false;
            } else {
                return true;
            }
        }
    }
}