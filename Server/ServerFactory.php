<?php
namespace Server;
use InvalidArgumentException;
use RuntimeExceptionException;

class ServerFactory {
    /**
     * 支持的服务器类型
     * 为一个数组映射，键为shema，值为对应的服务器类名
     * @const array
     */
    protected static $types = array(
        'tcp' => Server::class,
        'ws' => WebsocketServer::class
    );

    /**
     * 创建服务器实例
     * @param string uri 此参数形式为scheme//ip:port 例如tcp://127.0.0.1:8000
     * @return ServerInterface
     */
    public static function server($uri) {
        /**
         * uri由schema，IP和port组成
         *解析uri
         */
        $schema = substr($uri, 0, strpos($uri, "//"));
        $address = substr($uri, strpos($uri, "//")+2);
        list($ip, $port) = explode(':', $address);

        //如果uri不符合规则，则跑出InvalidAugumentException
       if(empty($schema) || empty($ip) || empty($port)) {
            throw new InvalidArgumentException('the argument is not correct.');
        }

        //根据shema，实例化对应的服务器类
        $serverName = @self::$types[$schema];
        if(empty($serverName)) {
            throw new RuntimeException('unsupported server type.');
        }

        return new $serverName($ip, $port);
    }
}