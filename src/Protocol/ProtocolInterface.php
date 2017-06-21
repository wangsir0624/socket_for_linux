<?php
namespace Wangjian\Socket\Protocol;

use Wangjian\Socket\Connection\ConnectionInterface;

interface ProtocolInterface {
    /**
     * get the protocol message length
     * @param string $buffer
     * @param ConnectionInterface $connection
     * @return int
     */
    public static function input($buffer, ConnectionInterface $connection);

    /**
     * encode
     * @param mixed $original
     * @param ConnectionInterface $connection
     * @return string
     */
    public static function encode($original, ConnectionInterface $connection);

    /**
     * decode
     * @param string $buffer
     * @param ConnectionInterface $connection
     * @return mixed  returns the original data
     */
    public static function decode($buffer, ConnectionInterface $connection);
}