<?php

declare(strict_types=1);

namespace chaser\http;

use chaser\stream\traits\ConnectedCommunicationUnpack;
use chaser\tcp\Client as TcpClient;

/**
 * http 客户端类
 *
 * @package chaser\http
 */
class Client extends TcpClient implements ServerUnpackInterface
{
    use ConnectedCommunicationUnpack;

    /**
     * 尝试解包
     *
     * @return string
     */
    protected function unpack(): string
    {
        $data = $this->recvBuffer;
        $this->recvBuffer = '';
        return $data;
    }
}
