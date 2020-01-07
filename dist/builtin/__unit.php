<?php
const UNIT_PATH = __DIR__ . DIRECTORY_SEPARATOR;

#public
const NL = "\n";





function is_uint($val): bool {
	return is_int($val) && $val >= 0;
}

function _iconv_strpos(string $str, string $search, int $offset = 0): int {
	$pos = iconv_strpos($str, $search, $offset);
	return $pos === false ? -1 : $pos;
}

function _iconv_strrpos(string $str, string $search, int $offset = 0): int {
	$pos = iconv_strrpos($str, $search, $offset);
	return $pos === false ? -1 : $pos;
}

function _strpos(string $master, string $search, int $offset = 0): int {
	$pos = strpos($master, $search, $offset);
	return $pos === false ? -1 : $pos;
}

function _strrpos(string $master, string $search, int $offset = 0): int {
	$pos = strrpos($master, $search, $offset);
	return $pos === false ? -1 : $pos;
}

function _str_replace(string $master, string $search, string $replacement): string {
	return str_replace($search, $replacement, $master);
}

function _array_search(array $master, $search): int {
	$index = array_search($search, $master);
	return $index === false ? -1 : $index;
}

function _dict_search(array $master, $search): string {
	$key = array_search($search, $master);
	return $key === false ? null : $key;
}

function regex_match(string $regex, string $subject): array {
	$matches = null;
	$count = preg_match($regex, $subject, $matches);
	return $count === 0 ? null : $matches;
}

function regex_matches(string $regex, string $subject): array {
	$matches = null;
	$count = preg_match_all($regex, $subject, $matches);
	return $count === 0 ? null : $matches;
}


// program end

# --- generates ---
const __AUTOLOADS = [
	'IView' => 'core.php'
];

spl_autoload_register(function ($class) {
	isset(__AUTOLOADS[$class]) && require UNIT_PATH . __AUTOLOADS[$class];
});

// end
