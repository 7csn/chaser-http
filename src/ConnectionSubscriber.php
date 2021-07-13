<?php

declare(strict_types=1);

namespace chaser\http;

use chaser\stream\subscriber\ConnectionSubscriber as StreamConnectionSubscriber;
use chaser\stream\traits\ConnectedCommunicationUnpackSubscribable;

/**
 * http 连接事件订阅类
 *
 * @package chaser\http
 */
class ConnectionSubscriber extends StreamConnectionSubscriber
{
    use ConnectedCommunicationUnpackSubscribable;

    /**
     * @inheritDoc
     */
    public static function events(): array
    {
        return ConnectedCommunicationUnpackSubscribable::events() + parent::events();
    }
}
