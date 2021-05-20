<?php

declare(strict_types=1);

namespace chaser\http;

use chaser\tcp\TcpConnectionSubscriber;

/**
 * http 连接事件订阅类
 *
 * @package chaser\http
 *
 * @property HttpConnection $connection
 */
class HttpConnectionSubscriber extends TcpConnectionSubscriber
{
}
