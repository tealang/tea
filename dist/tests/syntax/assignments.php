<?php
namespace tea\tests\syntax;

require_once __DIR__ . '/__unit.php';

// ---------
$a = 1;
$a += 1;
$a -= 1;
$a *= 1;
$a /= 1;
$a %= 1;
$a >>= 1;
$a <<= 1;
$a &= 1;
$a |= 1;
$a ^= 1;

$a += 123;
// ---------

// program end
