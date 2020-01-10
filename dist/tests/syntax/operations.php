<?php
namespace tea\tests\syntax;

use ErrorException;
use Exception;

// ---------
$some = 'abc' . (1 + (2 & 2) * 2 ** 3 / 5 % 6);

$uint_num = 123;
$int_num = 123;

$int_from_string = (int)'123';
$uint_from_string = abs((int)'-123');

$str_from_uint = (string)123;
$str_from_int = (string)-123;
$str_from_float = (string)123.123;

if (is_string($str_from_float)) {
	echo $str_from_float . ' is String', NL;
}
$num = 3;
$result = -$num * PI ** 2 - 12;

$is_greater = 3 > $num;

$b1 = true;
$b2 = !$b1;

$b3 = !($num - 3);
$b4 = !($num > 3);

$l1 = !($num < 0) && $num != 3;

is_int(1);
is_uint(2);
new \ErrorException('demo') instanceof Exception;

$a = 1;

$b = $a == 1 ? 'one' : ($a == 2 ? 'two' : ($a == 3 ? 'three' : 'other'));
$c = ($a ?: 1) ? ($a ?: 2) : ($a ?: 3);
$d = 0 ? 1 : (2 + $a ? $a : 3);

$e = $a ?? 1 ? $a ?? 2 : $a ?? 3;
// ---------

// program end
