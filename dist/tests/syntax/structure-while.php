<?php
namespace tea\tests\syntax;

// ---------
$i = 0;
try {
	while ($i < 10) {
		$i += 1;
		continue;
	}
}
catch (\Exception $ex) {
	// no any
}

do {
	$i -= 1;
	break;
} while ($i > 5);

$i = 0;
while (1) {
	while (true) {
		$i = $i + 1;
		if ($i > 10) {
			break 2;
		}
		else {
			continue 1;
		}
	}
}
// ---------

// program end
