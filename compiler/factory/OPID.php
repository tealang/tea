<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class OPID
{
	const NEW = 1;
	const CLONE = 2;

	const REFERENCE = 20;
	const IDENTITY = 21;
	const NEGATION = 22;
	const BITWISE_NOT = 23;
	const BOOL_NOT = 24;

	const PRE_INCREMENT = 30;
	const PRE_DECREMENT = 31;
	const POST_INCREMENT = 32;
	const POST_DECREMENT = 33;

	const DOT = 40;
	const CAST = 41;
	const PIPE = 42;

	const EXPONENTIATION = 50;
	const MULTIPLICATION = 51;
	const DIVISION = 52;
	const REMAINDER = 53;
	const ADDITION = 54;
	const SUBTRACTION = 55;

	const CONCAT = 60;
	const ARRAY_CONCAT = 61;
	// const PHP_ARRAY_UNION = 62;
	const MERGE = 63; // for dicts in tea

	const IS = 70; // is_type / instanceof
	const EQUAL = 71;
	const IDENTICAL = 72;
	const NOT_EQUAL = 73;
	const NOT_IDENTICAL = 74;
	const LESSTHAN = 75;
	const GREATERTHAN = 76;
	const LESSTHAN_OR_EQUAL = 77;
	const GREATERTHAN_OR_EQUAL = 78;
	const SPACESHIP = 79;

	const BOOL_AND = 90;
	// const BOOL_XOR = 91;
	const BOOL_OR = 92;

	const SHIFT_LEFT = 100;
	const SHIFT_RIGHT = 101;
	const BITWISE_AND = 102;
	const BITWISE_XOR = 103;
	const BITWISE_OR = 104;

	const NONE_COALESCING = 110;
	const TERNARY = 111;

	const ASSIGNMENT = 201;

	const YIELD_FROM = 210;
	const YIELD = 211;
	const PRINT = 212;

	const LOW_BOOL_AND = 220;
	const LOW_BOOL_XOR = 221;
	const LOW_BOOL_OR = 222;
}

// end
