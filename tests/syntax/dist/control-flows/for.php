<?php
namespace tests\syntax;

require_once dirname(__DIR__, 2) . '/__public.php';

// ---------
$list = [
	"k1" => "hi",
	"k2" => "hello"
];

foreach ($list as $value) {
	print($value . ',');
}

echo LF;

if ($list && count($list) > 0) {
	foreach ($list as $k => $v) {
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
	foreach (\range(0, 9) as $i) {
		$i + 3;
		echo $i, ',', LF;
	}
}
else {
	echo 'no loops', LF;
}

echo LF;

try {
	$__tmp0 = 9 * 2;
	$__tmp1 = 0 + 5;
	if ($__tmp0 >= $__tmp1) {
		foreach (\range($__tmp0, $__tmp1, -2) as $i) {
			echo $i, ',', LF;
		}
	}
	elseif ($list) {
		echo 'oh!', LF;
	}
}
catch (\Exception $ex) {
	// no any
}
finally {
	// no any
}

echo LF;
// ---------

// program end
