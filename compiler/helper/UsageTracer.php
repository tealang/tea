<?php
namespace Tea;

class UsageTracer
{
	protected static $tracing;

	public static function start()
	{
		self::$tracing = [microtime(true), round(memory_get_usage() / 1024, 1), round(memory_get_peak_usage() / 1024, 1)];
	}

	public static function end()
	{
		$uses = microtime(true) - self::$tracing[0];

		$items[] = 'Time: ' . round($uses, 3) . 's';
		$items[] = 'Memory: ' . self::$tracing[1] . '~' . round(memory_get_usage() / 1024, 1) . 'K';
		$items[] = 'Memory(peak): ' . self::$tracing[2] . '~' . round(memory_get_peak_usage() / 1024, 1) . 'K';

		return $items;
	}
}

