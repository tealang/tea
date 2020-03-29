<?php
namespace tea\tests\syntax;

require_once dirname(__DIR__, 1) . '/__public.php';

function fn1(callable $callee) {
	$unknow_type_value = $callee('hei');
}

function fn2(array &$dict) {
	$dict['num'] += 1;
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

$dict = ['num' => 1000];
fn2($dict);
echo "dict['num'] mutated by fn2: {$dict['num']}", LF;

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
