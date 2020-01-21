<?php
namespace tea\examples;

require_once __DIR__ . '/__unit.php';

// ---------
foreach (fib_generator() as $k => $v) {
	echo "{$k} ==> {$v}", NL;
}
// ---------

// program end
