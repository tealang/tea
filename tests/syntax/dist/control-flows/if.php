<?php
namespace tea\tests\syntax;

// ---------
$a = 0;
$b = 1;

try {
	if ($a) {
		// no any
	}
	elseif ($b) {
		// no any
	}
	else {
		// no any
	}
}
catch (\Exception $ex) {
	// no any
}
finally {
	// no any
}
// ---------

// program end
