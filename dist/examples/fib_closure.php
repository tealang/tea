<?php
namespace tea\examples;

require_once __DIR__ . '/__unit.php';

// ---------
$f = fib_closure();
for ($i = 0; $i <= 9; $i += 1) {
	echo "{$i} ==> " . $f(), NL;
}
// ---------

// program end
