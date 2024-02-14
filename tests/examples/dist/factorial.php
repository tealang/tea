<?php
namespace tests\examples;

require_once dirname(__DIR__, 1) . '/__public.php';

function factorial(int $n): int {
	if ($n > 1) {
		return $n * factorial($n - 1);
	}

	return 1;
}

#internal
const NUM = 10;

// ---------
echo NUM . '! = ' . factorial(NUM), LF;
// ---------

// program end
