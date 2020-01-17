<?php
namespace tea\examples;

require_once __DIR__ . '/__unit.php';

// ---------
$days = ['Monday', 'Tuesday', 'Wednesday'];
$items = [];
foreach ($days as $i => $day) {
	$items[] = ($i + 1) . ": {$day}";
}

echo implode(', ', $items), NL;

echo NL;

say_hello();

$dict = [];
set_field('the key', 'the value', $dict);
var_dump($dict);

show_file_path();
// ---------

// program end
