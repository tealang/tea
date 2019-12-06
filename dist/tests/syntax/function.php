<?php
namespace tea\tests\syntax;

use Exception;

// ---------
$a = 'fn0';
$b = Test1::class;
$c = get_class();
$c();

fn1('fn0');
fn1(function ($str) {
	return fn0($str);
});

fn2(Test1::class);

fn3(new Exception('message'), 'abc');
// ---------

// program end
