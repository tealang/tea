<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class TeaSyntax
{
	const OPERATORS = [
		[
			OPID::MEMBER_ACCESSING => ['.', OP_BIN, OP_NA],
			OPID::NULLSAFE_MEMBER_ACCESSING => ['?.', OP_BIN, OP_NA],
			// OPID::KEY_ACCESSING => ['[', OP_BIN, OP_NA],
			OPID::PIPE => ['::', OP_BIN, OP_NA],
			OPID::AS => ['#', OP_BIN, OP_NA],
		],
		// [
		// 	OPID::CALL => ['(', OP_POSTFIX, OP_L],
		// ],
		[
			OPID::CLONE => ['clone', OP_PREFIX, OP_NA],
		],
		[
			// OPID::REFERENCE => ['&', OP_PREFIX, OP_NA],
			OPID::NEGATION => ['-', OP_PREFIX, OP_NA],
			OPID::BITWISE_NOT => ['~', OP_PREFIX, OP_NA],
		],
		[
			OPID::MULTIPLICATION => ['*', OP_BIN, OP_L],
			OPID::DIVISION => ['/', OP_BIN, OP_L],
			OPID::REMAINDER => ['%', OP_BIN, OP_L],
		],
		[
			OPID::ADDITION => ['+', OP_BIN, OP_L],
			OPID::SUBTRACTION => ['-', OP_BIN, OP_L],
		],
		[
			OPID::SHIFT_LEFT => ['<<', OP_BIN, OP_L],
			OPID::SHIFT_RIGHT => ['>>', OP_BIN, OP_L],
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
			OPID::REPEAT => ['.*', OP_BIN, OP_L],
		],
		[
			OPID::CONCAT => ['.+', OP_BIN, OP_L],
		],
		[
			OPID::LESSTHAN => ['<', OP_BIN, OP_NON],
			OPID::GREATERTHAN => ['>', OP_BIN, OP_NON],
			OPID::LESSTHAN_OR_EQUAL => ['<=', OP_BIN, OP_NON],
			OPID::GREATERTHAN_OR_EQUAL => ['>=', OP_BIN, OP_NON],
			OPID::EQUAL => ['==', OP_BIN, OP_NON],
			OPID::IDENTICAL => ['===', OP_BIN, OP_NON],
			OPID::NOT_EQUAL => ['!=', OP_BIN, OP_NON],
			OPID::NOT_IDENTICAL => ['!==', OP_BIN, OP_NON],
			OPID::SPACESHIP => ['<=>', OP_BIN, OP_NON],
			OPID::IS => ['is', OP_BIN, OP_L], // is, is not
		],
		[
			OPID::BOOL_NOT => ['not', OP_PREFIX, OP_NA],
		],
		[
			OPID::BOOL_AND => ['and', OP_BIN, OP_L],
		],
		[
			// OPID::BOOL_XOR => ['xor', OP_BIN, OP_L],
			OPID::BOOL_OR => ['or', OP_BIN, OP_L],
		],
		[
			OPID::NONE_COALESCING => ['??', OP_BIN, OP_R],
		],
		[
			OPID::TERNARY => ['?', OP_TERNARY, OP_NON],
		],
		[
			OPID::ASSIGNMENT=> ['=', OP_ASSIGN, OP_R],
			OPID::ADD_ASSIGNMENT=> ['+=', OP_ASSIGN, OP_R],
			OPID::SUB_ASSIGNMENT=> ['-=', OP_ASSIGN, OP_R],
			OPID::MUL_ASSIGNMENT=> ['*=', OP_ASSIGN, OP_R],
			OPID::DIV_ASSIGNMENT=> ['/=', OP_ASSIGN, OP_R],
			OPID::EXPONENT_ASSIGNMENT => ['**=', OP_ASSIGN, OP_R],
			OPID::CONCAT_ASSIGNMENT=> ['.=', OP_ASSIGN, OP_R],
			OPID::REM_ASSIGNMENT=> ['%=', OP_ASSIGN, OP_R],
			OPID::BITAND_ASSIGNMENT=> ['&=', OP_ASSIGN, OP_R],
			OPID::BITOR_ASSIGNMENT=> ['|=', OP_ASSIGN, OP_R],
			OPID::BITXOR_ASSIGNMENT=> ['^=', OP_ASSIGN, OP_R],
			OPID::SHL_ASSIGNMENT=> ['<<=', OP_ASSIGN, OP_R],
			OPID::SHR_ASSIGNMENT=> ['>>=', OP_ASSIGN, OP_R],
			OPID::NULL_COALESCE_ASSIGNMENT=> ['??=', OP_ASSIGN, OP_R],
		],
	];
}


// end
