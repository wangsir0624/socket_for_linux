<?php
namespace Wangjian\Socket\Module\MessageModule;

use Wangjian\Socket\Connection\ConnectionInterface;

abstract class MessageHandler {
    abstract public function handleMessage(ConnectionInterface $connection);
}