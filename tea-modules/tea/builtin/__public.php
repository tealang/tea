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

function is_strict_array($it): bool {
	if (!is_array($it)) {
		return false;
	}

	if (empty($it)) {
		return true;
	}

	$keys = array_keys($it);
	return $keys === array_keys($keys);
}

function is_strict_dict($it): bool {
	if (!is_array($it) || empty($it)) {
		return false;
	}

	if (!isset($it[0])) {
		return true;
	}

	$keys = array_keys($it);
	return $keys !== array_keys($keys);
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

function _str_replace(string $master, $search, $replacement) {
	return str_replace($search, $replacement, $master);
}

function _array_search(array $master, $search) {
	$key = array_search($search, $master, true);
	return $key === false ? false : $key;
}

function array_last_index(array $array): int {
	return count($array) - 1;
}

function dict_get(array $dict, string $key) {
	return $dict[$key] ?? null;
}

function _dict_search(array $master, $search) {
	$key = array_search($search, $master, true);
	return $key === false ? false : (string)$key;
}

function html_encode(?string $string, int $flags = ENT_QUOTES) {
	return empty($string) ? $string : htmlspecialchars($string, $flags);
}

function html_decode(?string $string, int $flags = ENT_QUOTES) {
	return empty($string) ? $string : htmlspecialchars_decode($string, $flags);
}

function regex_test(string $regex, string $subject): bool {
	return preg_match($regex, $subject) ? true : false;
}

function regex_capture(string $regex, string $subject): ?array {
	$result = null;
	$count = preg_match($regex, $subject, $result);
	return $count === 0 ? null : $result;
}

function regex_capture_all(string $regex, string $subject): ?array {
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
