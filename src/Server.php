<?php

declare(strict_types=1);

namespace chaser\http;

use chaser\tcp\Server as TcpServer;

/**
 * http 服务器类
 *
 * @package chaser\http
 */
class Server extends TcpServer
{
    /**
     * @inheritDoc
     */
    protected function connection($socket): Connection
    {
        return new Connection($this->container, $this, $this->reactor, $socket);
    }
}
