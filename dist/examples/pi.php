<?php
namespace tea\examples;

require_once __DIR__ . '/__unit.php';

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

echo "Computed PI value: {$computed_pi}", NL;
echo "PHP constant M_PI value: " . M_PI, NL;
echo "PHP function pi() value: " . pi(), NL;
// ---------

// program end
