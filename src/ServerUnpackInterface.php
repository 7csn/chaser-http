<?php

declare(strict_types=1);

namespace chaser\http;

/**
 * http 服务端解包接口
 *
 * @package chaser\http
 */
interface ServerUnpackInterface
{
    /**
     * 请求行默认长度上限 4K
     */
    public const MAX_REQUEST_LINE_SIZE = 4 << 10;

    /**
     * 请求头默认长度上限 512K
     */
    public const MAX_HEADER_SIZE = 512 << 10;

    /**
     * 请求主体默认长度上限 1M
     */
    public const MAX_BODY_SIZE = 1 << 10 << 10;

    /**
     * 支持的请求方法列表
     */
    public const REQUEST_METHODS = ['OPTIONS', 'HEAD', 'GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * 协议版本
     *
     * @var string[]
     */
    public const PROTOCOL_VERSIONS = ['1.0', '1.1'];
}
