<?php
namespace tea\tests\syntax;

require_once __DIR__ . '/__unit.php';

#internal
class Data {
	const ABC = 11;
	public static $num = 3000;
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
echo 'num referenced by fn2: ' . $num, NL;
echo 'arr[0] referenced by fn2: ' . $arr[0], NL;
echo 'Data.num referenced by fn2: ' . Data::$num, NL;

echo fn3(123, function ($a) {
	return $a . ' has called';
}, function ($error) {
	// no any
}), NL;

echo fn3('any...', function (string $caller) {
	return "{$caller} has called!";
}), NL;
// ---------

// program end
