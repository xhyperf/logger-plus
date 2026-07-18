<?php

declare(strict_types=1);

namespace XHyperf\LoggerPlus\Database;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\Events\QueryExecuted;
use Hyperf\Event\Contract\ListenerInterface;
use XHyperf\LoggerPlus\Log;

class DbQueryLog implements ListenerInterface
{
    use DbLogTrait;

    const string MASK = '@__u003f__@';

    public function __construct(protected ConfigInterface $config)
    {
    }

    public function listen(): array
    {
        return [
            QueryExecuted::class,
        ];
    }

    public function process(object $event): void
    {
        if (! $event instanceof QueryExecuted) {
            return;
        }

        $sql = $event->sql;
        if (array_is_list($event->bindings)) {
            $sql = str_replace('%', self::MASK, $sql);
            $sql = strtr($sql, ['?' => "'%s'"]);
            $sql = vsprintf($sql, $event->bindings);
            $sql = str_replace(self::MASK, '%', $sql);
        } else {
            $bindings = [];

            foreach ($event->bindings as $key => $value) {
                $bindings[':' . $key] = "'$value'";
            }

            $sql = strtr($sql, $bindings);
        }

        $data = [
            'sql'        => $sql,
            'query_time' => $event->time / 1000,
            ...$this->getIdx(),
        ];

        if ($this->traceEnable()) {
            $data += $this->getTrace();
        }

        Log::gather('sql', $data);
    }
}
