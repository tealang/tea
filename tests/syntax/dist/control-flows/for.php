<?php
namespace tea\tests\syntax;

\Swoole\Runtime::enableCoroutine();

require_once dirname(__DIR__, 2) . '/__public.php';

// ---------
$arr = [
	"k1" => "hi",
	"k2" => "hello"
];

foreach (range(0, 9) as $val) {
	echo $val, ',';
}

echo LF;

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
	foreach (\xrange(0, 9) as $i) {
		$i + 3;
		echo $i, ',';
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
		foreach (\xrange($__tmp0, $__tmp1, -2) as $i) {
			echo $i, ',';
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

echo LF;
// ---------

\Swoole\Event::wait();

// program end
