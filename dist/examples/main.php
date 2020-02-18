<?php
namespace tea\examples;

require_once __DIR__ . '/__unit.php';

function say_hello(string $name = 'World') {
	echo 'Hello, ' . $name . '!', LF;
}

function set_field(string $key, string $value, array &$dict) {
	$dict[$key] = $value;
}

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

echo implode(', ', $items), LF;

echo LF;

say_hello();

$dict = [];
set_field('the key', 'the value', $dict);
var_dump($dict);

show_file_path();
// ---------

// program end
