<?php
namespace tea\samples;

require_once __DIR__ . '/__unit.php';

// ---------
$f = fib_with_closure();
for ($i = 0; $i <= 9; $i += 1) {
	echo "{$i} ==> " . $f(), NL;
}
// ---------

// program end
