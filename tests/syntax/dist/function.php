<?php
namespace tea\tests\syntax;

require_once dirname(__DIR__, 1) . '/__public.php';

function fn1(callable $callee) {
	$unknow_type_value = $callee('hei');
}

function fn2(int &$n) {
	$n += 1;
}

#internal
class Data {
	const ABC = 11;
	public static $num = 3000;
}

function fn3($some, callable $done, callable $error = null): string {
	return $done('A cool man') . ' with ' . $some;
}

// ---------
$a_function = 'tea\tests\syntax\fn0';
$a_string = "string";
$a_function('call with callable type');

fn1('tea\tests\syntax\fn0');
fn1(function ($str) {
	return fn0($str);
});

$num = 1000;
$arr = [2000];

fn2($num);
fn2($arr[0]);
fn2(Data::$num);
echo 'num referenced by fn2: ' . $num, LF;
echo 'arr[0] referenced by fn2: ' . $arr[0], LF;
echo 'Data.num referenced by fn2: ' . Data::$num, LF;

echo fn3(123, function ($a) {
	return $a . ' has called';
}, function ($error) {
	// no any
}), LF;

echo fn3('any...', function (string $caller) {
	return "{$caller} has called!";
}), LF;
// ---------

// program end
