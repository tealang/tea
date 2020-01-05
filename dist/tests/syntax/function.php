<?php
namespace tea\tests\syntax;

require_once __DIR__ . '/__unit.php';

#internal
class ClassForFunctionDemo {
	// no any
}

// ---------
$a = 'tea\tests\syntax\fn0';
$b = ClassForFunctionDemo::class;
$c = get_class();
new $c();

fn1('tea\tests\syntax\fn0');
fn1(function ($str) {
	return fn0($str);
});

fn2(ClassForFunctionDemo::class);

echo fn3('any...', function (string $caller) {
	return "{$caller} has called!";
}), NL;
// ---------

// program end
