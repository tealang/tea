<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class TeaSyntax
{
	const OPERATORS = [
		1 => [
			OPID::CLONE => ['clone', OP_PRE, OP_NA],
			OPID::DOT => ['.', OP_BIN, OP_L, OP_NA],
			OPID::CAST => ['#', OP_BIN, OP_L, OP_NA],
			OPID::PIPE => ['::', OP_BIN, OP_L, OP_NA],
		],
		2 => [
			// OPID::REFERENCE => ['&', OP_PRE, OP_NA],
			OPID::NEGATION => ['-', OP_PRE, OP_NA],
			OPID::BITWISE_NOT => ['~', OP_PRE, OP_NA],
		],
		3 => [
			OPID::MULTIPLICATION => ['*', OP_BIN, OP_L],
			OPID::DIVISION => ['/', OP_BIN, OP_L],
			OPID::REMAINDER => ['%', OP_BIN, OP_L],
			OPID::BITWISE_AND => ['&', OP_BIN, OP_L],
			OPID::SHIFT_LEFT => ['<<', OP_BIN, OP_L],
			OPID::SHIFT_RIGHT => ['>>', OP_BIN, OP_L],
		],
		4 => [
			OPID::ADDITION => ['+', OP_BIN, OP_L],
			OPID::SUBTRACTION => ['-', OP_BIN, OP_L],
			// OPID::BITWISE_XOR => ['^', OP_BIN, OP_L],
			OPID::BITWISE_OR => ['|', OP_BIN, OP_L],
		],
		5 => [
			OPID::CONCAT => ['concat', OP_BIN, OP_L],
			OPID::ARRAY_UNION => ['union', OP_BIN, OP_L],
		],
		6 => [
			OPID::LESSTHAN => ['<', OP_BIN, OP_NON],
			OPID::GREATERTHAN => ['>', OP_BIN, OP_NON],
			OPID::LESSTHAN_OR_EQUAL => ['<=', OP_BIN, OP_NON],
			OPID::GREATERTHAN_OR_EQUAL => ['>=', OP_BIN, OP_NON],
			OPID::EQUAL => ['==', OP_BIN, OP_NON],
			OPID::IDENTICAL => ['===', OP_BIN, OP_NON],
			OPID::NOT_EQUAL => ['!=', OP_BIN, OP_NON],
			OPID::NOT_IDENTICAL => ['!==', OP_BIN, OP_NON],
			OPID::SPACESHIP => ['<=>', OP_BIN, OP_NON],
			OPID::IS => ['is', OP_BIN, OP_L], // is_type / instanceof
		],
		7 => [
			OPID::BOOL_NOT => ['not', OP_PRE, OP_NA],
		],
		8 => [
			OPID::BOOL_AND => ['and', OP_BIN, OP_L],
		],
		9 => [
			// OPID::BOOL_XOR => ['xor', OP_BIN, OP_L],
			OPID::BOOL_OR => ['or', OP_BIN, OP_L],
		],
		10 => [
			OPID::NONE_COALESCING => ['??', OP_BIN, OP_L],
		],
		11 => [
			OPID::TERNARY => ['?', OP_TERNARY, OP_NON],
		],
		// 12 => [
		// 	OPID::ASSIGNMENT => ['=', OP_BIN, OP_R],
		// ],
	];
}

// end
