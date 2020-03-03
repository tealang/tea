<?php
namespace tea\tests\syntax;

require_once __DIR__ . '/__public.php';

// ---------
$arr = [
	"k1" => "hi",
	"k2" => "hello"
];

foreach (range(0, 9) as $val) {
	echo $val, LF;
}

if ($arr && count($arr) > 0) {
	foreach ($arr as $k => $v) {
		// no any
	}
}
elseif (true) {
	// no any
}
else {
	// no any
}

if (0 <= 9) {
	for ($i = 0; $i <= 9; $i += 1) {
		$i + 3;
		echo $i, LF;
	}
}
else {
	echo 'no loops', LF;
}

try {
	$__tmp0 = 9 * 2;
	$__tmp1 = 0 + 5;
	if ($__tmp0 >= $__tmp1) {
		for ($i = $__tmp0; $i >= $__tmp1; $i -= 2) {
			echo $i, LF;
		}
	}
	elseif ($arr) {
		echo 'oh!';
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
