<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use XHyperf\LoggerPlus\Formatter\DockerFluentFormatter;
use XHyperf\LoggerPlus\Formatter\LineFormatter;
use XHyperf\LoggerPlus\Formatter\StdoutFormatter;
use XHyperf\LoggerPlus\Handler\StdoutHandler;
use XHyperf\LoggerPlus\Log;

use function Hyperf\Support\env;

$fluentFormatter = [
    'class'       => DockerFluentFormatter::class,
    'constructor' => [
        'levelTag' => true,
    ],
];

$handlers = [];

// 输出到文件
if (Log::isOutputFile()) {
    $handlers[] = 'file';
}

// 输出到控制台
if (Log::isOutputConsole()) {
    $handlers[] = 'console';
}

return [
    'default'  => env('LOG_CHANNEL', 'stack'),
    'channels' => [
        'stack' => [
            'handlers' => $handlers,
        ],
        // 输出到文件
        'file'    => [
            'handler'   => [
                'class'       => StreamHandler::class,
                'constructor' => [
                    'stream' => BASE_PATH . '/runtime/logs/hyperf.log',
                    'level'  => env('LOG_LEVEL', Level::Debug->value),
                ],
            ],
            'formatter' => Log::isOutputFluent()
                ? $fluentFormatter
                : [
                    'class'       => LineFormatter::class,
                    'constructor' => [
                        'format'                => null,
                        'dateFormat'            => 'Y-m-d H:i:s',
                        'allowInlineLineBreaks' => true,
                    ],
                ],
        ],
        // 输出到控制台
        'console' => [
            'handler'   => [
                'class'       => StdoutHandler::class,
                'constructor' => [
                    'level' => env('LOG_LEVEL', Level::Debug->value),
                ],
            ],
            'formatter' => Log::isOutputFluent()
                ? $fluentFormatter
                : [
                    'class'       => StdoutFormatter::class,
                    'constructor' => [
                        'allowInlineLineBreaks' => true,
                    ],
                ],
        ],
    ],
];
