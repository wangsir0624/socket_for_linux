<?php
namespace Wangjian\Socket\Module\Responder;

use Wangjian\Socket\Protocol\HttpProtocol;
use Wangjian\Socket\Protocol\HttpMessage;
use Wangjian\Socket\Connection\ConnectionInterface;

class ZipResponder extends AbstractResponder
{
    public function respond(HttpMessage $message, ConnectionInterface $connection, $onlyHeader = false) {
    }
}