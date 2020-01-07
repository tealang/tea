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

echo fn2('any...', function (string $caller) {
	return "{$caller} has called!";
}), NL;

fn3(123, function ($a, $b) {
	return $a . $b;
});
// ---------

// program end
