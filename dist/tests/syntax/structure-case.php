<?php
namespace tea\tests\syntax;

require_once __DIR__ . '/__unit.php';

// ---------
$a = 0;
$b = 1;
$num = 1;

try {
	switch ($num) {
		case $a * 3:
			switch ($num) {
				case $b:
					$b = $num * 5;
					echo $b, NL;
					break;
				case 1:
				case 2:
				case 3:
					$a = $num * 2;
					break 2;

				default:
					echo 'not matched.', NL;
					break;
			}
			break;
		case 10:
		case 100:
			echo $num, NL;
			break;
		default:
			if ($num > 10) {
				echo 'num is greater than 10.', NL;
			}
	}
}
catch (\Exception $ex) {
	// no any
}
finally {
	echo 'finally!', NL;
}
// ---------

// program end
