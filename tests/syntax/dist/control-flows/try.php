<?php
namespace tea\tests\syntax;

require_once dirname(__DIR__, 2) . '/__public.php';

// ---------
try {
	// no any
}
catch (\Exception $_) {
	// no any
}
finally {
	echo 'do finally', LF;
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
	echo $ex->getMessage(), LF;
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
	echo $ex->getMessage(), LF;
}
finally {
	// no any
}
// ---------

\Swoole\Event::wait();

// program end
