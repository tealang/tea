<?php
namespace tea\examples;

require_once __DIR__ . '/__unit.php';

function fib_generator(int $num = 9): \Generator {
	$c = null;

	$a = 0;
	$b = 1;
	for ($i = 0; $i <= $num; $i += 1) {
		$c = $b;
		$b = $a + $b;
		$a = $c;
		yield $a;
	}
}

// ---------
foreach (fib_generator() as $k => $v) {
	echo "{$k} ==> {$v}", NL;
}
// ---------

// program end
