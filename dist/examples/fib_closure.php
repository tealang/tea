<?php
namespace tea\examples;

require_once __DIR__ . '/__unit.php';

function fib_closure(): callable {
	$a = 0;
	$b = 1;
	return function () use(&$b, &$a) {
		$c = $b;
		$b = $a + $b;
		$a = $c;
		return $a;
	};
}

// ---------
$f = fib_closure();
for ($i = 0; $i <= 9; $i += 1) {
	echo "{$i} ==> " . $f(), NL;
}
// ---------

// program end
