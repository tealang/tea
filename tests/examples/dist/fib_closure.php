<?php
namespace tests\examples;

require_once dirname(__DIR__, 1) . '/__public.php';

function fib_closure() {
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
foreach (\range(0, 9) as $i) {
	echo "{$i} ==> " . $f(), LF;
}
// ---------

// program end
