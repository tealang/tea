<?php
namespace tea\examples;

require_once __DIR__ . '/__public.php';

function fib_generator(int $num = 9): \Generator {
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
	echo "{$k} ==> {$v}", LF;
}
// ---------

// program end
