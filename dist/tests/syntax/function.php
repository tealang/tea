<?php
namespace tea\tests\syntax;

require_once __DIR__ . '/__unit.php';

// ---------
$a_function = 'tea\tests\syntax\fn0';
$a_function('some');

fn1('tea\tests\syntax\fn0');
fn1(function ($str) {
	return fn0($str);
});

$num = 1000;
fn2($num);
echo 'num referenced by fn2: ' . $num, NL;

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
