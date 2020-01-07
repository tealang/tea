<?php
namespace tea\tests\syntax;

// ---------
$num1 = 123456;
$num2 = -123456;

$dec = 123456789;
$hex = 0xffffff;
$oct = 01234567;
$bin = 0b01010101;

is_uint($num1);
is_uint($num2);
is_uint($dec);
is_int($dec);

$float_num0 = 123;
$float_num1 = 123.123;
$float_num2 = 123e3;
$float_num2 = 123e+3;
$float_num3 = 123e-6;
$float_num3 = 123.1e+6;
$float_num3 = 0.1231e-10;

$num_str = strval($dec);
$num_str = (string)123.1;
// ---------

// program end
