<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class PHPSyntax
{
	const OPERATORS = [
		[
			OPID::NEW => ['new', OP_PRE, OP_NA],
			OPID::CLONE => ['clone', OP_PRE, OP_NA],
			OPID::DOT => ['.', OP_BIN, OP_L, OP_NA],
		],
		[
			OPID::EXPONENTIATION => ['**', OP_BIN, OP_R],
		],
		[
			OPID::CAST => ['#', OP_BIN, OP_L, OP_NA],
			OPID::REFERENCE => ['&', OP_PRE, OP_NA],
			OPID::IDENTITY => ['+', OP_PRE, OP_NA],
			OPID::NEGATION => ['-', OP_PRE, OP_NA],
			OPID::PRE_INCREMENT => ['++', OP_PRE, OP_NA],
			OPID::PRE_DECREMENT => ['--', OP_PRE, OP_NA],
			OPID::POST_INCREMENT => ['++', OP_POST, OP_NA],
			OPID::POST_DECREMENT => ['--', OP_POST, OP_NA],
			OPID::BITWISE_NOT => ['~', OP_PRE, OP_NA],
		],
		[
			OPID::MULTIPLICATION => ['*', OP_BIN, OP_L],
			OPID::DIVISION => ['/', OP_BIN, OP_L],
			OPID::REMAINDER => ['%', OP_BIN, OP_L],
		],
		[
			OPID::IS => ['instanceof', OP_BIN, OP_L],
		],
		[
			OPID::BOOL_NOT => ['!', OP_PRE, OP_NA],
		],
		[
			OPID::ADDITION => ['+', OP_BIN, OP_L],
			OPID::SUBTRACTION => ['-', OP_BIN, OP_L],
			// OPID::PHP_ARRAY_UNION => ['+', OP_BIN, OP_L],
		],
		[
			OPID::SHIFT_LEFT => ['<<', OP_BIN, OP_L],
			OPID::SHIFT_RIGHT => ['>>', OP_BIN, OP_L],
		],
		[
			OPID::CONCAT => ['.', OP_BIN, OP_L], 	// PHP version > 8.0.0
		],
		[
			OPID::LESSTHAN => ['<', OP_BIN, OP_NON],
			OPID::GREATERTHAN => ['>', OP_BIN, OP_NON],
			OPID::LESSTHAN_OR_EQUAL => ['<=', OP_BIN, OP_NON],
			OPID::GREATERTHAN_OR_EQUAL => ['>=', OP_BIN, OP_NON],
		],
		[
			OPID::EQUAL => ['==', OP_BIN, OP_NON],
			OPID::IDENTICAL => ['===', OP_BIN, OP_NON],
			OPID::NOT_EQUAL => ['!=', OP_BIN, OP_NON],
			OPID::NOT_IDENTICAL => ['!==', OP_BIN, OP_NON],
			OPID::SPACESHIP => ['<=>', OP_BIN, OP_NON],
		],
		[
			OPID::BITWISE_AND => ['&', OP_BIN, OP_L],
		],
		[
			OPID::BITWISE_XOR => ['^', OP_BIN, OP_L],
		],
		[
			OPID::BITWISE_OR => ['|', OP_BIN, OP_L],
		],
		[
			OPID::BOOL_AND => ['&&', OP_BIN, OP_L],
		],
		[
			OPID::BOOL_OR => ['||', OP_BIN, OP_L],
		],
		[
			OPID::NONE_COALESCING => ['??', OP_BIN, OP_L],
		],
		[
			OPID::TERNARY => ['?', OP_TERNARY, OP_NON],
		],
		[
			OPID::ASSIGNMENT => ['=', OP_BIN, OP_R],
		],
		[
			OPID::YIELD_FROM => ['yield from', OP_PRE, OP_NA],
		],
		[
			OPID::YIELD => ['yield', OP_PRE, OP_NA],
		],
		[
			OPID::PRINT => ['print', OP_PRE, OP_NA],
		],
		[
			OPID::LOW_BOOL_AND => ['and', OP_BIN, OP_L],
		],
		[
			OPID::LOW_BOOL_XOR => ['or', OP_BIN, OP_L],
		],
		[
			OPID::LOW_BOOL_OR => ['xor', OP_BIN, OP_L],
		],
	];
}

// end
