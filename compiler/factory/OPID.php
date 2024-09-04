<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class OPID
{
	const NEW = 1;
	const CLONE = 2;

	const STATIC_ACCESSING = 5; // static member accessing
	const MEMBER_ACCESSING = 6;
	const NULLSAFE_MEMBER_ACCESSING = 7;

	const REFERENCE = 20;
	const IDENTITY = 21;  		// conversion of string to int/float, eg. +"123" to 123
	const NEGATION = 22;
	const BITWISE_NOT = 23;
	const BOOL_NOT = 24;

	const PRE_INCREMENT = 30;
	const PRE_DECREMENT = 31;
	const POST_INCREMENT = 32;
	const POST_DECREMENT = 33;

	const ERROR_CONTROL = 34;

	const AS = 41;
	const PIPE = 42;

	const EXPONENTIATION = 50;
	const MULTIPLICATION = 51;
	const DIVISION = 52;
	const REMAINDER = 53;
	const ADDITION = 54;
	const SUBTRACTION = 55;

	const REPEAT = 60;
	const CONCAT = 61;
	const ARRAY_CONCAT = 62;
	// const PHP_ARRAY_UNION = 65;
	// const MERGE = 66; // for dicts

	const IS = 70; // is, is not
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
	const ADD_ASSIGNMENT = 202;
	const SUB_ASSIGNMENT = 203;
	const MUL_ASSIGNMENT = 204;
	const DIV_ASSIGNMENT = 205;
	const EXPONENT_ASSIGNMENT = 206;
	const CONCAT_ASSIGNMENT = 207;
	const REM_ASSIGNMENT = 208;
	const BITAND_ASSIGNMENT = 209;
	const BITOR_ASSIGNMENT = 210;
	const BITXOR_ASSIGNMENT = 211;
	const SHL_ASSIGNMENT = 212;
	const SHR_ASSIGNMENT = 213;
	const NULL_COALESCE_ASSIGNMENT = 214;

	const YIELD_FROM = 220;
	const YIELD = 221;
	const PRINT = 222;

	const LOW_BOOL_AND = 230;
	const LOW_BOOL_XOR = 231;
	const LOW_BOOL_OR = 232;

	const SPREAD = 250;
}

// end
