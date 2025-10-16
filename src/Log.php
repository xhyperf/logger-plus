<?php

declare(strict_types=1);

namespace XHyperf\LoggerPlus;

use Hyperf\Collection\Arr;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\HttpMessage\Server\RequestParserInterface;
use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Stringable\Str;
use JetBrains\PhpStorm\ArrayShape;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Coroutine as SwooleCo;
use Throwable;

use function Hyperf\Config\config;
use function Hyperf\Support\env;

/**
 * @method static emergency(string $message, array $context = [], string $channel = 'log', string $group = 'default')
 * @method static alert(string $message, array $context = [], string $channel = 'log', string $group = 'default')
 * @method static critical(string $message, array $context = [], string $channel = 'log', string $group = 'default')
 * @method static error(string $message, array $context = [], string $channel = 'log', string $group = 'default')
 * @method static warning(string $message, array $context = [], string $channel = 'log', string $group = 'default')
 * @method static notice(string $message, array $context = [], string $channel = 'log', string $group = 'default')
 * @method static info(string $message, array $context = [], string $channel = 'log', string $group = 'default')
 * @method static debug(string $message, array $context = [], string $channel = 'log', string $group = 'default')
 * @see \Hyperf\Logger\Logger
 */
class Log
{
    /**
     * 日志输出到文件
     */
    const int OUTPUT_TYPE_FILE = 0x1;

    /**
     * 日志输出到控制台
     */
    const int OUTPUT_TYPE_CONSOLE = 0x2;

    /**
     * 日志是否按 fluent 格式输出 (256)
     */
    const int OUTPUT_TYPE_FLUENT = 0x100;

    const string REQUEST_ID_KEY = 'X-Request-Id';

    /**
     * 初始化请求ID
     * @param string $prefix 前缀
     * @param string $suffix 后缀
     * @return void
     */
    public static function init(string $prefix = '', string $suffix = ''): void
    {
        Context::set(self::REQUEST_ID_KEY, $prefix . ($suffix ? '.' . $suffix : bin2hex(openssl_random_pseudo_bytes(16))));
    }

    /**
     * 记录集合式的日志信息
     * @param string $tag   集合名称
     * @param mixed  $data  数据
     * @param string $group 日志配置分组
     * @return void
     */
    public static function gather(string $tag, mixed $data, string $group = 'default'): void
    {
        static::log('notice', 'gather', compact('tag', 'data'), 'gather', $group);
    }

    /**
     * trace 日志
     * @param Throwable|null $e       异常
     * @param string         $msg     消息内容
     * @param string         $channel 通道
     * @param string         $group   分组
     * @return void
     */
    public static function trace(?Throwable $e = null, string $msg = '', string $channel = 'log', string $group = 'default'): void
    {
        if ($e instanceof Throwable) {
            $msg               = sprintf('%s [%s] in %s', $msg ?: $e->getMessage(), $e->getLine(), $e->getFile());
            $trace             = self::getTrace($e, true);
            $trace['previous'] = self::getTrace($e->getPrevious(), true);
            self::log('error', $msg, $trace, $channel, $group);
        } else {
            self::gather('trace', ['trace' => debug_backtrace()], $group);
        }
    }

    /**
     * 获取 trace 数据
     * @param Throwable|null $e
     * @param bool           $detail
     * @return array
     */
    #[ArrayShape(['code' => "int", 'message' => "string", 'file' => "string", 'line' => "int", 'trace' => "array|string"])]
    public static function getTrace(?Throwable $e, bool $detail = false): array
    {
        if (! $e) {
            return [];
        }
        $trace = [
            'code'    => $e->getCode(),
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ];

        if ($detail) {
            $trace['trace'] = self::isOutputFluent() ? $e->getTrace() : $e->getTraceAsString();
        }

        return $trace;
    }

    /**
     * request 日志
     * @param mixed $body
     * @param int   $status
     * @return void
     * @throws
     */
    public static function request(mixed $body, int $status = 200): void
    {
        if (Context::get(__METHOD__)) {
            return;
        }

        if ($body instanceof ResponseInterface) {
            $contentType = strtolower(Str::before($body->getHeaderLine('content-type'), ';'));
            $body        = (string)$body->getBody();
            $body        = str_contains($contentType, 'image') ? str_replace("\r\n", ' %n% ', substr($body, 0, 256)) : $body;

            $parser = ApplicationContext::getContainer()->get(RequestParserInterface::class);
            if ($parser->has($contentType) && $body) {
                $body = $parser->parse($body, $contentType);
            }
        }

        if (! (! empty($body['code']) || config(ConfigKey::RESPONSE_ENABLE, env('LOG_RESPONSE', false)))) {
            if (is_array($body)) {
                unset($body['data']);
            } else {
                $body = null;
            }
        }

        $request = ApplicationContext::getContainer()->get(ServerRequestInterface::class);
        $log     = [
            'url'      => (string)$request->getUri(),
            'status'   => $status,
            'runtime'  => round(microtime(true) - ($request->getServerParams()['request_time_float'] ?? 0), 6),
            'method'   => $request->getMethod(),
            'server'   => Arr::except($request->getServerParams(), ['request_method', 'request_uri', 'path_info']),
            'route'    => $request->getAttribute(Dispatched::class)->handler->callback ?? [],
            'request'  => [
                'query'  => $request->getQueryParams(),
                'post'   => $request->getParsedBody(),
                'header' => array_map(function ($v) {
                    return implode(", ", $v);
                }, $request->getHeaders()),
                'cookie' => $request->getCookieParams(),
            ],
            'response' => $body,
        ];

        if (! ($status >= 200 && $status < 300) && $status != 404) {
            $log['__level__'] = 'critical';
        }

        self::gather('request', $log);

        Context::set(__METHOD__, true);
    }

    /**
     * 获取请求ID
     * 当前协程里没有请求ID时，会去查询父协程中的请求ID，直到顶级协程为止
     * @return string
     */
    public static function getRequestId(): string
    {
        $currCoId = $coId = SwooleCo::getCid();
        do {
            $requestId = Context::get(self::REQUEST_ID_KEY, '', $coId);
        } while (! $requestId && $coId && $coId > 0 && ($coId = SwooleCo::getPcid($coId)));

        $currCoId == $coId || Context::set(Log::REQUEST_ID_KEY, $requestId);

        return $requestId;
    }

    /**
     * 记录日志
     * @param string $level   日志级别
     * @param string $message 日志内容
     * @param array  $context 上下文
     * @param string $channel 日志通道
     * @param string $group   分组
     * @return void
     * @throws
     */
    public static function log(string $level, string $message, array $context = [], string $channel = 'log', string $group = 'default'): void
    {
        $channel = strtoupper($channel);

        ApplicationContext::getContainer()->get(LoggerFactory::class)
                          ->get($channel, $group)
                          ->$level(
                              $message,
                              $context
                          );
    }

    /**
     * 禁止 request 日志
     * @return void
     */
    public static function disableRequestLog(): void
    {
        Context::set(self::class . '::request', true);
    }

    /**
     * 获取输出类型
     * @param bool $fromConfig 是否从配置文件中获取，为否时从环境变量获取
     * @return int
     */
    public static function getOutputType(bool $fromConfig = false): int
    {
        $type = $fromConfig
            ? config(ConfigKey::OUTPUT_TYPE, Log::OUTPUT_TYPE_CONSOLE)
            : env("LOG_OUTPUT_TYPE", Log::OUTPUT_TYPE_CONSOLE);

        return (int)$type;
    }

    /**
     * 是否输出到文件
     * @return bool
     */
    public static function isOutputFile(): bool
    {
        return (self::getOutputType() & self::OUTPUT_TYPE_FILE) == self::OUTPUT_TYPE_FILE;
    }

    /**
     * 是否输出到控制台
     * @return bool
     */
    public static function isOutputConsole(): bool
    {
        return (self::getOutputType() & self::OUTPUT_TYPE_CONSOLE) == self::OUTPUT_TYPE_CONSOLE;
    }

    /**
     * 是否输出为 Fluent 格式
     * @param bool $fromConfig 是否从配置文件中获取，为否时从环境变量获取
     * @return bool
     */
    public static function isOutputFluent(bool $fromConfig = false): bool
    {
        return (self::getOutputType($fromConfig) & self::OUTPUT_TYPE_FLUENT) == self::OUTPUT_TYPE_FLUENT;
    }

    public static function __callStatic($method, $args)
    {
        static::log($method, ...$args);
    }
}