<?php
namespace tea\tests\syntax;

require_once dirname(__DIR__, 1) . '/__public.php';

function demo_range(int $start, int $end): \Iterator {
	$i = $start;
	while ($i <= $end) {
		yield $i;
		$i += 1;
	}
}

// ---------
foreach (demo_range(0, 5) as $v) {
	echo $v, LF;
}
// ---------

// program end
