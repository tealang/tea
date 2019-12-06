<?php
namespace tea\tests\syntax;

use ErrorException;

// ---------
try {
	// no any
}
catch (\Exception $ex) {
	// no any
}
finally {
	// no any
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
	// no any
}
finally {
	// no any
}

try {
	foreach ([] as $v) {
		throw new ErrorException('some message');
	}
}
catch (\ErrorException $ex) {
	// no any
}
finally {
	// no any
}
// ---------

// program end
