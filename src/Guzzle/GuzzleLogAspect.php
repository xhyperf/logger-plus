<?php

declare(strict_types=1);

namespace XHyperf\LoggerPlus\Guzzle;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\TransferStats;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Psr\Http\Message\RequestInterface;
use XHyperf\LoggerPlus\Log;


class GuzzleLogAspect extends AbstractAspect
{
    public array $classes = [
        HandlerStack::class . '::create',
    ];

    /**
     * @param ProceedingJoinPoint $proceedingJoinPoint
     * @return mixed
     * @throws
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        $stack = $proceedingJoinPoint->process();
        $stack->push(self::httpLog(), 'http_log');

        return $stack;
    }

    public static function httpLog(): callable
    {
        return static function (callable $handler): callable {
            return static function (RequestInterface $request, array $options) use ($handler) {
                $options['on_stats'] = function (TransferStats $stats) use ($options) {
                    $request  = $stats->getRequest();
                    $response = $stats->getResponse();

                    Log::gather('http', [
                        'uri'           => (string)$request->getUri(),
                        'method'        => $request->getMethod(),
                        'transfer_time' => $stats->getTransferTime(),
                        'request'       => [
                            'header' => array_map(function ($v) {
                                return implode(", ", $v);
                            }, $request->getHeaders()),
                            'post'   => self::getBody($request),
                        ],
                        'response'      => [
                            'code'   => $response?->getStatusCode(),
                            'header' => array_map(function ($v) {
                                return implode(", ", $v);
                            }, $response?->getHeaders() ?: []),
                            'body'   => self::getBody($response),
                        ],
                    ]);
                };

                return $handler($request, $options);
            };
        };
    }

    /**
     * @param $obj
     * @return mixed|string
     */
    public static function getBody($obj): mixed
    {
        $body = (string)$obj?->getBody();
        $type = $obj?->getHeaderLine('Content-Type') ?: '';

        if (str_contains($type, 'multipart') || str_contains($type, 'image')) {
            $body = str_replace("\r\n", ' %n% ', substr($body, 0, 256));
        } elseif ((str_contains($type, 'json')) || (str_starts_with($body, '{'))) {
            $body = json_decode($body, true) ?: $body;
        } elseif (str_contains($type, 'x-www-form-urlencoded')) {
            parse_str($body, $result);
            $body = $result;
        }

        return $body;
    }
}