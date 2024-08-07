<?php
namespace tests\syntax;

require_once dirname(__DIR__, 1) . '/__public.php';

#internal
const PI = 3.1415926;

// ---------
$some = 'abc' . (1 + 2 & fmod(2 * pow(2, 3) / 5, 6));

$uint_num = 123;
$int_num = -123;

$uint_from_string = uint_ensure((int)'123');

$str_from_uint = (string)123;
$str_from_float = (string)123.123;

$dict = ['a' => 1, 'b' => '100'];
$val = isset($dict['b']) ? (int)$dict['b'] : 0;
echo 'Value for ?? expression is: ', LF;
var_dump($val);

if (is_string($str_from_float)) {
	echo $str_from_float . ' is String', LF;
}

if (!is_string($uint_num)) {
	echo $uint_num . ' is not String', LF;
}

if ($uint_num !== null) {
	echo $uint_num . ' is not None', LF;
}

$str_for_find = 'abc123';
$found = mb_strpos($str_for_find, 'abc');
if ($found === false) {
	echo '"abc" has not be found in ' . $str_for_find, LF;
}

$str = 'abc';
$num = 3;
$result = -mb_strlen($str) + -$num * pow(PI, 2) - 12;

$is_greater = 3 > $num;

$b1 = true;
$b2 = !$b1;

$b3 = !($num - 3);
$b4 = !($num > 3);

$l1 = !($num < 0) && $num != 3;

is_int(1);
is_uint(2);
!is_int(1);

$exception = new \ErrorException('demo');
$exception instanceof \Exception;
!$exception instanceof \Exception;

$a = 1;

$b = $a == 1 ? 'one' : ($a == 2 ? 'two' : ($a == 3 ? 'three' : 'other'));
$c = ($a ?: 1) ? ($a ?: 2) : ($a ?: 3);
$d = 0 ? 1 : (2 + $a ? $a : 3);

$e = $a ?? 1 ? $a ?? 2 : $a ?? 3;
// ---------

// program end
