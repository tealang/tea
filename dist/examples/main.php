<?php
namespace tea\examples;

require_once __DIR__ . '/__unit.php';

// ---------
say_hello();

$dict = [];
$dict = set_field('the key', 'the value', $dict);
var_dump($dict);

show_file_path();
// ---------

// program end
