<?php
namespace tests\syntax;

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
// ---------

// program end
