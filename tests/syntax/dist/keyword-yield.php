<?php
namespace tea\tests\syntax;

use Exception;

require_once __DIR__ . '/__public.php';

// ---------
foreach (xrange(0, 10) as $v) {
	echo $v, LF;
}
// ---------

// program end
