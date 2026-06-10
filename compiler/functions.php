<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

// array_key_last undefined in version <= 7.2
if (!function_exists('array_key_last')) {
	function array_key_last(array $items) {
		$keys = array_keys($items);
		return end($keys);
	}
}

function halt(string $msg) {
	echo LF, $msg, LF, LF;
	exit;
}

function error(string $msg) {
	echo "\nError: $msg\n";
}

function println(string ...$contents) {
	foreach ($contents as $content) {
		echo $content;
	}

	echo LF;
}

function dump(...$args) {
	echo LF;
	$dumper = new Dumper(['unit', 'program'], 3);
	foreach ($args as $arg) {
		$str = $dumper->stringify($arg, 0);
		$str = str_replace('Tea\\', '', $str);
		echo $str, LF;
	}
}

function strip_unit_path(string $file) {
	static $prefix_len;
	if ($prefix_len === null) {
		$prefix_len = strlen(UNIT_PATH);
	}

	return substr($file, $prefix_len);
}

// process the options of command-line interface
function process_cli_options(array $argv, array $allow_list = []) {
	$opts = [];
	for ($i = 1; $i < count($argv); $i++) {
		$item = $argv[$i];
		if ($item[0] === '-' && strlen($item) > 1) {
			if ($item[1] === '-') {
				// the '--key' style
				$key = substr($item, 2);
				$value = true;
			}
			else {
				$key = $item[1];
				$value = strlen($item) > 2 ? substr($item, 2) : true;
			}

			if (!in_array($key, $allow_list, true)) {
				throw new Exception("Invalid command-line option key '{$key}'");
			}

			$opts[$key] = $value;
		}
		else {
			$opts[] = $item;
		}
	}

	return $opts;
}

function get_traces(int $trace_start = 0) {
	$traces = '';
	$trace_items = debug_backtrace();
	$len = count($trace_items) - 1;
	for ($i = $trace_start + 1; $i < $len; $i++) {
		$item = $trace_items[$i];

		$args = [];
		foreach ($item['args'] as $arg) {
			$args[] = json_encode($arg, JSON_UNESCAPED_UNICODE);
		}

		$traces .= sprintf("%s:%d \t%s(%s)\n",
			$item['file'],
			$item['line'],
			$item['function'],
			join(', ', $args)
		);
	}

	$traces = str_replace(TEA_BASE_PATH, '', $traces);
	return $traces;
}

