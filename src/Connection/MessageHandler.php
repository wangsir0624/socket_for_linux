<?php
namespace Wangjian\Socket\Connection;

abstract class MessageHandler {
    abstract public function handleMessage(ConnectionInterface $connection);
}