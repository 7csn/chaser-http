<?php

declare(strict_types=1);

namespace chaser\http;

use chaser\http\message\ServerRequest;
use chaser\http\message\Stream;
use chaser\http\message\Uri;
use chaser\stream\exception\UnpackedException;
use chaser\stream\traits\ConnectedCommunicationUnpack;
use chaser\tcp\Connection as TcpConnection;

/**
 * http 连接类
 *
 * @package chaser\http
 *
 * @property int $maxRequestLineSize    请求行长度上限
 * @property int $maxHeaderSize         请求头长度上限
 * @property int $maxBodySize           请求主体长度上限
 */
class Connection extends TcpConnection implements ServerUnpackInterface
{
    use ConnectedCommunicationUnpack;

    /**
     * 当前请求方法
     *
     * @var string|null
     */
    protected ?string $requestMethod = null;

    /**
     * 请求 uri
     *
     * @var string|null
     */
    protected ?string $requestUri = null;

    /**
     * 当前请求协议
     *
     * @var string|null
     */
    protected ?string $protocolVersion = null;

    /**
     * 请求行长度
     *
     * @var int|null
     */
    protected ?int $requestLineSize = null;

    /**
     * 消息头长度
     *
     * @var int|null
     */
    protected ?int $headerSize = null;

    /**
     * 消息体长度
     *
     * @var int|null
     */
    protected ?int $bodySize = null;

    protected static array $unpackBytesCache = [];

    /**
     * @inheritDoc
     */
    public static function configurations(): array
    {
        return [
                'maxRequestLineSize' => self::MAX_REQUEST_LINE_SIZE,
                'maxHeaderSize' => self::MAX_HEADER_SIZE,
                'maxBodySize' => self::MAX_BODY_SIZE
            ] + parent::configurations();
    }

    /**
     * @inheritDoc
     */
    public static function subscriber(): string
    {
        return ConnectionSubscriber::class;
    }

    /**
     * 尝试解析包长度
     *
     * @return int|null
     * @throws UnpackedException
     */
    protected function tryToGetPackageSize(): ?int
    {
        $cacheable = !isset($this->recvBuffer[512]);

        if ($cacheable && isset($sizes[$this->recvBuffer])) {
            return self::$unpackBytesCache[$this->recvBuffer]['unpackBytes'];
        }

        if ($this->requestLineSize === null) {

            $requestLine = strstr($this->recvBuffer, "\r\n", true);

            if ($requestLine === false) {
                // 请求行长度验证
                if (strlen($this->recvBuffer) > $this->maxRequestLineSize) {
                    $this->exception(414);
                }
                return null;
            }

            // 请求行长度验证
            if (strlen($requestLine) > $this->maxRequestLineSize) {
                $this->exception(414);
            }

            // 首行格式错误
            $explode = explode(' ', $requestLine);
            if (count($explode) !== 3) {
                $this->exception(400);
            }

            [$method, $uri, $protocol] = $explode;

            // 验证方法
            if (!in_array($method, self::REQUEST_METHODS)) {
                $this->exception(405);
            }
            $this->requestMethod = $method;

            // 验证 uri
            if (parse_url($uri) === false) {
                $this->exception(400);
            }
            $this->requestUri = $uri;

            // 验证协议
            if (!preg_match('/^HTTP\/([\d.]+)$/', $protocol, $match)) {
                $this->exception(505);
            }
            if (!in_array($match[1], self::PROTOCOL_VERSIONS)) {
                $this->exception(505);
            }

            $this->protocolVersion = $match[1];
            $this->requestLineSize = strlen($requestLine);
        }

        // 请求头部
        $header = strstr($this->recvBuffer, "\r\n\r\n", true);

        if ($header === false) {
            if (strlen($this->recvBuffer) - $this->maxRequestLineSize - 2 >= $this->maxHeaderSize) {
                $this->exception(431);
            }
            return null;
        }

        $this->headerSize = strlen($header) - $this->requestLineSize - 2;

        // 请求头长度超过限制
        if ($this->headerSize > $this->maxHeaderSize) {
            $this->exception(431);
        }

        if (in_array($this->requestMethod, ['GET', 'OPTIONS', 'HEAD'])) {
            $this->bodySize = 0;
        } elseif (preg_match("/\r\nContent-Length: ?(\d+)/i", $header, $match) > 0) {
            $this->bodySize = (int)$match[1];
        } elseif ($this->requestMethod === 'DELETE') {
            $this->bodySize = 0;
        } else {
            $this->exception(400);
        }

        if ($this->bodySize > $this->maxBodySize) {
            $this->exception(413);
        }

        $unpackBytes = $this->requestLineSize + 2 + $this->headerSize + 4 + $this->bodySize;

        if ($cacheable) {
            self::$unpackBytesCache[$this->recvBuffer] = [
                'unpackBytes' => $unpackBytes,
                'requestSize' => $this->requestLineSize,
                'requestUri' => $this->requestUri,
                'requestMethod' => $this->requestMethod,
                'protocolVersion' => $this->protocolVersion,
                'headerSize' => $this->headerSize,
                'bodySize' => $this->bodySize
            ];
            if (count(self::$unpackBytesCache) > 512) {
                unset(self::$unpackBytesCache[key(self::$unpackBytesCache)]);
            }
        }

        return $unpackBytes;
    }

    /**
     * 获取请求
     *
     * @return ServerRequest
     */
    protected function getRequest(): ServerRequest
    {
        static $requests = [];

        $cacheable = $this->unpackBytes < 512 && isset(self::$unpackBytesCache[$this->recvBuffer]);

        if ($cacheable) {
            $package = substr($this->recvBuffer, 0, $this->unpackBytes);
            if (count($requests) >= 512) {
                unset($requests[key($requests)]);
            }

            return $requests[$package] ??= (function () {
                [
                    'requestSize' => $this->requestLineSize,
                    'requestUri' => $this->requestUri,
                    'requestMethod' => $this->requestMethod,
                    'protocolVersion' => $this->protocolVersion,
                    'headerSize' => $this->headerSize,
                    'bodySize' => $this->bodySize
                ] = self::$unpackBytesCache[$this->recvBuffer];
                return $this->getRequest2();
            })();
        }

        return $this->getRequest2();
    }

    /**
     * 获取请求
     *
     * @return ServerRequest
     */
    protected function getRequest2(): ServerRequest
    {
        $uri = new Uri($this->requestUri);

        $headerData = substr($this->recvBuffer, $this->requestLineSize + 2, $this->headerSize);
        $headers = [];
        foreach (explode("\r\n", $headerData) as $headerData) {
            if (strpos($headerData, ':', 2)) {
                [$key, $value] = explode(':', $headerData);
                $headers[$key] = ltrim($value);
            }
        }

        $bodyData = substr($this->recvBuffer, $this->requestLineSize + 2 + $this->headerSize + 4, $this->bodySize);
        $body = Stream::create($bodyData);

        return new ServerRequest($this->requestMethod, $uri, [], $headers, $body, $this->protocolVersion);
    }

    /**
     * 解包数据重置
     */
    protected function unpackReset(): void
    {
        $this->requestUri = null;
        $this->unpackBytes = null;
        $this->requestMethod = null;
        $this->requestLineSize = null;
        $this->protocolVersion = null;
        $this->headerSize = null;
        $this->bodySize = null;
    }

    /**
     * 异常操作
     *
     * @param int $code
     * @param string $reasonPhrase
     * @throws UnpackedException
     */
    protected function exception(int $code, string $reasonPhrase = '')
    {
        throw new UnpackedException($reasonPhrase, $code);
    }
}
