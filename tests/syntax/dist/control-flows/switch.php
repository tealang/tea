<?php
namespace tea\tests\syntax;

require_once dirname(__DIR__, 2) . '/__public.php';

// ---------
$num = 1;
$str = 'abc';

try {
	switch ($num) {
		case $num * 3:
			switch ($str) {
				case 'some' . $num:
				case 'hello':
				case ('a' . 123):
					$str = $num * 5;
					echo $str, LF;
					break;
				case 'a':
					$num = $num * 2;
					break 2;

				default:
					echo 'not matched.', LF;
					break;
			}
			break;
		case 10:
		case 100:
			echo $num, LF;
			break;
		default:
			if ($num > 10) {
				echo 'num is greater than 10.', LF;
			}
	}
}
catch (\Exception $ex) {
	// no any
}
finally {
	echo 'finally!', LF;
}
// ---------

// program end
