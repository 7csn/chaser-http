<?php

declare(strict_types=1);

namespace chaser\http;

use chaser\http\message\ServerRequest;
use chaser\http\message\Stream;
use chaser\http\message\Uri;
use chaser\stream\exception\UnpackedException;
use chaser\stream\traits\ConnectedCommunicationUnpack;
use chaser\tcp\Connection as TcpConnection;
use Psr\Http\Message\ServerRequestInterface;

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
     * 整包字节数
     *
     * @var int|null
     */
    protected ?int $unpackBytes = null;

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
     * 验证请求方法所需长度
     *
     * @return int
     */
    protected static function requestMethodCheckingSize(): int
    {
        static $checking = null;

        return $checking ??= array_reduce(self::REQUEST_METHODS, function ($checking, $method) {
                return max($checking, strlen($method));
            }, 0) + 2;
    }

    /**
     * 尝试解析包长度
     *
     * @return int|null
     * @throws UnpackedException
     */
    protected function tryToGetPackageSize(): ?int
    {
        if ($this->requestMethod === null) {
            $check = $this->checkRequestMethod();
            if ($check !== true) {
                return $check;
            }
        }

        if ($this->requestLineSize === null) {
            $check = $this->checkRequestLine();
            if ($check !== true) {
                return $check;
            }
        }

        // 请求头部
        $header = strstr($this->recvBuffer, "\r\n\r\n", true);

        if ($header === false) {
            $limit = $this->maxRequestLineSize + 2 + $this->maxHeaderSize + 4;
            if (strlen($this->recvBuffer) >= $limit) {
                $this->exception(414);
            }
            return null;
        }

        $this->headerSize = strlen($header) - $this->requestLineSize - 2;

        // 请求头长度超过限制
        if ($this->headerSize > $this->maxHeaderSize) {
            $this->exception(414);
        }

        if (in_array($this->requestMethod, ['GET', 'OPTIONS', 'HEAD']) === true) {
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

        return $this->requestLineSize + 2 + $this->headerSize + 4 + $this->bodySize;
    }

    /**
     * 获取请求
     *
     * @return ServerRequestInterface
     */
    protected function getRequest(): ServerRequestInterface
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
     * 验证请求方法
     *
     * @return bool|null
     * @throws UnpackedException
     */
    protected function checkRequestMethod(): ?bool
    {
        // 长度不够跳过验证
        if (strlen($this->recvBuffer) < self::requestMethodCheckingSize()) {
            return null;
        }

        foreach (self::REQUEST_METHODS as $method) {
            if (str_starts_with($this->recvBuffer, $method)) {
                $this->requestMethod = $method;
                break;
            }
        }

        // 方法名不合法
        if ($this->requestMethod === null) {
            $this->exception(405);
        }

        // 格式错误：~^METHOD /~
        if (substr($this->recvBuffer, strlen($this->requestMethod), 2) !== ' /') {
            $this->exception(400);
        }

        return true;
    }

    /**
     * 验证请求行
     *
     * @return bool|null
     * @throws UnpackedException
     */
    protected function checkRequestLine(): ?bool
    {
        // 请求行数据
        $requestLine = strstr($this->recvBuffer, "\r\n", true);

        // 解析出请求行，则进行验证
        if ($requestLine === false) {
            // 长度未超限，则跳过验证；长度超限，抛出异常
            if (strlen($this->recvBuffer) < $this->maxRequestLineSize + 2) {
                return null;
            }
            $this->exception(414);
        }

        // 首行长度超过限制
        if (strlen($requestLine) > $this->maxRequestLineSize) {
            $this->exception(404);
        }

        // 首行格式错误
        $explode = explode(' ', $requestLine);
        if (count($explode) !== 3) {
            $this->exception(400);
        }

        [, $uri, $protocol] = $explode;

        // 验证 uri
        if (parse_url($uri) === false) {
            $this->exception(400);
        }
        $this->requestUri = $uri;

        // 验证协议
        if (!preg_match('/^HTTP\/([\d.]+)$/', $protocol, $match)) {
            $this->exception(400);
        }

        if (in_array($match[1], self::PROTOCOL_VERSIONS) === false) {
            $this->exception(500);
        }

        $this->protocolVersion = $match[1];
        $this->requestLineSize = strlen($requestLine);

        return true;
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
