<?php
const UNIT_PATH = __DIR__ . DIRECTORY_SEPARATOR;

const LF = "\n";

function is_uint($val): bool {
	return is_int($val) && $val >= 0;
}

function uint_ensure(int $num) {
	if ($num < 0) {
		throw new \ErrorException('Cannot use ' . $num . ' as a UInt value');
	}

	return $num;
}

function xrange(int $start, int $end, int $step = 1): \Iterator {
	$i = $start;
	if ($step > 0) {
		while ($i <= $end) {
			yield $i;
			$i += $step;
		}
	}
	elseif ($step < 0) {
		while ($i >= $end) {
			yield $i;
			$i += $step;
		}
	}
	else {
		throw new \LogicException('Parameter "step" should not be 0');
	}
}

function html_encode(?string $string, int $flags = ENT_QUOTES) {
	return empty($string) ? $string : htmlspecialchars($string, $flags);
}

function html_decode(?string $string, int $flags = ENT_QUOTES) {
	return empty($string) ? $string : htmlspecialchars_decode($string, $flags);
}

function _std_split(string $it, string $separator) {
	return explode($separator, $it);
}

function _std_replace(string $it, $search, $replacement) {
	return str_replace($search, $replacement, $it);
}

function _has(array $it, string|int $key) {
	return array_key_exists($key, $it);
}

function _vals_contains(array $it, $val, bool $strict = false) {
	return in_array($val, $it, $strict);
}

function _array_find(array $it, $val) {
	$key = array_search($val, $it, true);
	return $key === false ? false : $key;
}

function _std_array_map(array $it, callable $callback) {
	return array_map($callback, $it);
}

function _std_join(array $it, string $separator) {
	return implode($separator, $it);
}

function _dict_find(array $it, $val) {
	$key = array_search($val, $it, true);
	return $key === false ? false : (string)$key;
}

function _dict_get(array $it, string|int $key) {
	return $it[$key] ?? null;
}

function _regex_test(string $regex, string $subject): bool {
	return preg_match($regex, $subject) ? true : false;
}

function _regex_capture(string $regex, string $subject): ?array {
	$result = null;
	$count = preg_match($regex, $subject, $result);
	return $count === 0 ? null : $result;
}

function _regex_capture_all(string $regex, string $subject): ?array {
	$results = null;
	$count = preg_match_all($regex, $subject, $results);
	return $results;
}


// program end

// autoloads
const __AUTOLOADS = [
	'IView' => 'dist/core.php'
];

spl_autoload_register(function ($class) {
	isset(__AUTOLOADS[$class]) && require UNIT_PATH . __AUTOLOADS[$class];
});

// end
