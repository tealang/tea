<?php
namespace tests\examples;

require_once dirname(__DIR__, 1) . '/__public.php';

#internal
function say_hello($name = 'World') {
	echo 'Hello, ' . $name . '!', LF;
}

#internal
function set_field(string $key, string $value, array &$dict) {
	$dict[$key] = $value;
}

#internal
function show_file_path(string $filename = null) {
	if ($filename === null) {
		$filename = __FILE__;
	}
	else {
		$filename = realpath($filename);
	}

	echo $filename, LF;
}

// ---------
$days = ['Monday', 'Tuesday', 'Wednesday'];
$items = [];
foreach ($days as $i => $day) {
	$items[] = ($i + 1) . ": {$day}";
}

echo _std_join($items, ', '), LF;

echo LF;

say_hello();

$dict = [];
set_field('the key', 'the value', $dict);
var_dump($dict);

show_file_path();
// ---------

// program end
