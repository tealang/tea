<?php
namespace tea\examples;

require_once __DIR__ . '/__public.php';

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
foreach (\xrange(0, 9) as $i) {
	echo "{$i} ==> " . $f(), LF;
}
// ---------

// program end
