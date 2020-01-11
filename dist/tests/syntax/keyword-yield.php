<?php
namespace tea\tests\syntax;

use Exception;

require_once __DIR__ . '/__unit.php';

// ---------
foreach (xrange(0, 10) as $v) {
	echo $v, NL;
}
// ---------

// program end
