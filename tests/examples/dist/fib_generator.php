<?php
namespace tests\examples;

require_once dirname(__DIR__, 1) . '/__public.php';

#internal
function fib_generator($num = 9): \Iterator {
	$a = 0;
	$b = 1;
	foreach (\range(0, $num) as $i) {
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
