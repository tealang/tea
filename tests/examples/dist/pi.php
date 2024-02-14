<?php
namespace tests\examples;

require_once dirname(__DIR__, 1) . '/__public.php';

#internal
const MIN_FLOAT = 1.11e-16;

// ---------
$computed_pi = 2.0;
$tmp = 2.0;
$a = 1;
$b = 3;

while ($tmp > MIN_FLOAT) {
	$tmp *= $a / $b;
	$computed_pi += $tmp;
	$a += 1;
	$b += 2;
}

echo "Computed PI value: {$computed_pi}", LF;
echo "PHP constant M_PI value: " . M_PI, LF;
echo "PHP function pi() value: " . pi(), LF;
// ---------

// program end
