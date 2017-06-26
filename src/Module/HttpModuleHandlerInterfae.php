<?php
namespace Wangjian\Socket\Module;

use Wangjian\Socket\Protocol\HttpMessage;
use Wangjian\Socket\Connection\ConnectionInterface;

interface HttpModuleHandlerInterface {
    public function handler(HttpMessage $message, ConnectionInterface $connection);
}