<?php
namespace tea\samples;

require_once __DIR__ . '/__unit.php';

// ---------
foreach (fib_with_generator() as $k => $v) {
	echo "{$k} ==> {$v}", NL;
}
// ---------

// program end
