<?php
namespace Wangjian\Socket\Module\Responder;

use Wangjian\Socket\Protocol\HttpMessage;
use Wangjian\Socket\Connection\ConnectionInterface;

abstract class AbstractResponder {
    protected $nextResponder = null;

    public function setNextResponder(AbstractResponder $responder) {
        $this->nextResponder = $responder;
    }

    public function next(HttpMessage $message, ConnectionInterface $connection, $onlyHeader = false) {
        if($this->nextResponder instanceof self) {
            return $this->nextResponder->respond($message, $connection, $onlyHeader);
        }
    }

    abstract public function respond(HttpMessage $message, ConnectionInterface $connection, $onlyHeader = false);
}