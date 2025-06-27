<?php
namespace tests\syntax;

$num = 3;
$matched = match($num) {
	1, 2 => true,
	default => false,
};

if (match($num) {
	1, 2, 3 => true,
	default => false
} === true) {
	//
}

var_dump(1, 2, );

