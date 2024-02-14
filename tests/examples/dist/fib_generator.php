<?php
namespace tests\examples;

require_once dirname(__DIR__, 1) . '/__public.php';

function fib_generator(int $num = 9): \Iterator {
	$a = 0;
	$b = 1;
	foreach (\xrange(0, $num) as $i) {
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
