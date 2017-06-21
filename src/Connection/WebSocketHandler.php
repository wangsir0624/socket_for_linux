<?php
namespace Wangjian\Socket\Connection;

class WebSocketHandler extends MessageHandler {
    public function handleMessage(ConnectionInterface $connection) {
        $buffer = fread($connection->stream, $connection->recv_buffer_size);

        $connection->recv_buffer .= $buffer;

        $protocol = $connection->server->protocol;

        $connection->current_package_size = $protocol::input($connection->recv_buffer, $connection);

        if($connection->current_package_size != 0) {
            $buffer = substr($connection->recv_buffer, 0, $connection->current_package_size);
            $connection->recv_buffer = substr($connection->recv_buffer, $connection->current_package_size);
            $connection->current_package_size = 0;

            $protocol::decode($buffer, $connection);

            if(!empty($connection->recv_buffer)) {
                call_user_func(array($connection, 'handleMessage'));
            }
        }
    }
}