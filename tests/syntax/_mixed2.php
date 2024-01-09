<?php
namespace tests\syntax;

const MY_CONST = [
	// member 1
	'k1' => 'v1', // inline comment

	// member 2
	'k2' => 'v3',

	/**
	 * multiline comments
	 */
	'k3' => 'v3',

	// other comments
];

function php_get_num(): int {
	return __LINE__;
}
