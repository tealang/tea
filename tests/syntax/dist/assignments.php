<?php
namespace tea\tests\syntax;

require_once __DIR__ . '/__public.php';

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
