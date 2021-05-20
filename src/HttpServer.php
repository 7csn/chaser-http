<?php

declare(strict_types=1);

namespace chaser\http;

use chaser\tcp\TcpServer;

/**
 * http 服务器类
 *
 * @package chaser\http
 */
class HttpServer extends TcpServer
{
    /**
     * @inheritDoc
     */
    public function connection($socket): HttpConnection
    {
        return new HttpConnection($this, $this->reactor, $socket);
    }
}
