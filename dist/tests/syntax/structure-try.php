<?php
namespace tea\tests\syntax;

use Exception;
use ErrorException;

require_once __DIR__ . '/__unit.php';

// ---------
try {
	// no any
}
catch (\Exception $ex) {
	echo $ex->getMessage(), NL;
}
finally {
	echo 'do finally', NL;
}

try {
	if (1) {
		// no any
	}
	else {
		// no any
	}
}
catch (\Exception $ex) {
	echo $ex->getMessage(), NL;
}
finally {
	// no any
}

try {
	foreach ([0, 1, 2, 3] as $v) {
		if ($v == 2) {
			throw new \ErrorException('some message');
		}
	}
}
catch (\ErrorException $ex) {
	echo $ex->getMessage(), NL;
}
finally {
	// no any
}
// ---------

// program end
