<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

const _PREFIX_OP_PRECEDENCES = [
	// L2
	_NEGATION => 2, _BITWISE_NOT => 2,
	// _REFERENCE => 2,

	// L8
	_NOT => 8,
];

const _BINARY_OP_PRECEDENCES = [
	// L1
	_DOT => 1,  // class/object
	_NOTIFY => 1, // the callback notify
	// () []
	_DOUBLE_COLON => 1, // type cast

	// L3
	_EXPONENTIATION => 3, // math

	// L4
	_MULTIPLICATION => 4, _DIVISION => 4, _REMAINDER => 4, // math
	_SHIFT_LEFT => 4, _SHIFT_RIGHT => 4, _BITWISE_AND => 4, // bitwise

	// L5
	_ADDITION => 5, _SUBTRACTION => 5, // math
	_BITWISE_OR => 5, _BITWISE_XOR => 5, // bitwise

	// L6
	_CONCAT => 6, // String / Array,  concat to the end of the left expression
	_VCAT => 6, // Array,  concat to the end of the left array expression
	// _MERGE => 6, // Dict / Array,  merge by key or index
	// 'pop', 'take'  // todo?

	// L7 comparisons
	'<' => 7, '>' => 7, '<=' => 7, '>=' => 7,
	_EQUAL => 7, _IDENTICAL => 7, _NOT_EQUAL => 7, _NOT_IDENTICAL => 7, '<=>' => 7,
	_IS => 7, // type / class, and maybe pattern?

	// L9
	_AND => 9,

	// L10
	_OR => 10, // _XOR => 10,

	// L11
	_NONE_COALESCING => 11,

	// L12
	_CONDITIONAL => 12, // ternary conditional expression
];

class OperatorFactory
{
	static $_dot;
	// static $_notify; // has parsed in the parser

	static $_negation;
	static $_bitwise_not; // eg. ~0 == -1
	// static $_reference;

	static $_cast;

	static $_exponentiation;

	static $_multiplication;
	static $_division;
	static $_remainder;

	static $_addition;
	static $_subtraction;

	static $_concat;
	static $_vcat;
	// static $_merge;

	static $_shift_left;
	static $_shift_right;

	static $_is;
	static $_lessthan;
	static $_morethan;
	static $_lessthan_or_equal;
	static $_morethan_or_equal;
	static $_equal;
	static $_strict_equal;

	static $_not_equal;
	static $_strict_not_equal;

	static $_comparison;

	static $_bitwise_and;
	static $_bitwise_xor;
	static $_bitwise_or;

	static $_none_coalescing;
	static $_conditional; // exp0 ? exp1 : exp2, or exp0 ?: exp1

	static $_bool_not;
	static $_bool_and;
	// static $_bool_xor;
	static $_bool_or;

	private static $prefix_op_symbol_map = [];
	private static $binary_op_symbol_map = [];

	private static $number_operators;
	private static $bitwise_operators;
	private static $bool_operators;

	public static function init()
	{
		self::$_negation = self::create_prefix_operator(_NEGATION);
		self::$_bitwise_not = self::create_prefix_operator(_BITWISE_NOT);
		// self::$_reference = self::create_prefix_operator(_REFERENCE);
		self::$_bool_not = self::create_prefix_operator(_NOT);

		self::$_dot = self::create_normal_operator(_DOT);
		// self::$_notify = self::create_normal_operator(_NOTIFY);

		self::$_cast = self::create_normal_operator(_DOUBLE_COLON);

		self::$_exponentiation = self::create_normal_operator(_EXPONENTIATION);

		self::$_multiplication = self::create_normal_operator(_MULTIPLICATION);
		self::$_division = self::create_normal_operator(_DIVISION);
		self::$_remainder = self::create_normal_operator(_REMAINDER);

		self::$_addition = self::create_normal_operator(_ADDITION);
		self::$_subtraction = self::create_normal_operator(_SUBTRACTION);

		self::$_concat = self::create_normal_operator(_CONCAT);
		self::$_vcat = self::create_normal_operator(_VCAT);
		// self::$_merge = self::create_normal_operator(_MERGE);

		self::$_shift_left = self::create_normal_operator(_SHIFT_LEFT);
		self::$_shift_right = self::create_normal_operator(_SHIFT_RIGHT);

		self::$_is = self::create_normal_operator(_IS);

		self::$_lessthan = self::create_normal_operator('<');
		self::$_morethan = self::create_normal_operator('>');
		self::$_lessthan_or_equal = self::create_normal_operator('<=');
		self::$_morethan_or_equal = self::create_normal_operator('>=');
		self::$_comparison = self::create_normal_operator('<=>');

		self::$_equal = self::create_normal_operator(_EQUAL);
		self::$_strict_equal = self::create_normal_operator(_IDENTICAL);

		self::$_not_equal = self::create_normal_operator(_NOT_EQUAL);
		self::$_strict_not_equal = self::create_normal_operator(_NOT_IDENTICAL);

		self::$_bitwise_and = self::create_normal_operator(_BITWISE_AND);
		self::$_bitwise_xor = self::create_normal_operator(_BITWISE_XOR);
		self::$_bitwise_or = self::create_normal_operator(_BITWISE_OR);

		self::$_none_coalescing = self::create_normal_operator(_NONE_COALESCING);

		self::$_conditional = self::create_normal_operator(_CONDITIONAL);

		self::$_bool_and = self::create_normal_operator(_AND);
		self::$_bool_or = self::create_normal_operator(_OR);

		// number
		self::$number_operators = [
			self::$_addition, self::$_subtraction, self::$_multiplication, self::$_division, self::$_remainder, self::$_exponentiation,
			self::$_comparison
		];

		// bitwise
		self::$bitwise_operators = [self::$_bitwise_and, self::$_bitwise_xor, self::$_bitwise_or, self::$_shift_left, self::$_shift_right];

		// bool
		self::$bool_operators = [
			self::$_bool_and, self::$_bool_or,
			self::$_equal, self::$_strict_equal, self::$_not_equal, self::$_strict_not_equal, self::$_is,
			self::$_lessthan, self::$_morethan, self::$_lessthan_or_equal, self::$_morethan_or_equal
		];
	}

	/**
	 * 设置待渲染的目标语言符号映射和优先级
	 * @map array [src sign => dist sign]
	 * @precedences array [dist sign => precedence]
	 */
	public static function set_render_options(array $map, array $precedences)
	{
		foreach (self::$binary_op_symbol_map as $sign => $symbol) {
			$dist_sign = $map[$sign] ?? $sign;

			if (!isset($precedences[$dist_sign])) {
				throw new Exception("Dist precedence of '$dist_sign' not found.");
			}

			$symbol->dist_sign = $dist_sign;
			$symbol->dist_precedence = $precedences[$dist_sign];
		}
	}

	public static function is_number_operator(Operator $symbol)
	{
		return in_array($symbol, self::$number_operators, true);
	}

	public static function is_bitwise_operator(Operator $symbol)
	{
		return in_array($symbol, self::$bitwise_operators, true);
	}

	public static function is_bool_operator(Operator $symbol)
	{
		return in_array($symbol, self::$bool_operators, true);
	}

	public static function get_prefix_operator(?string $sign)
	{
		return self::$prefix_op_symbol_map[$sign] ?? null;
	}

	public static function get_normal_operator(?string $sign)
	{
		return self::$binary_op_symbol_map[$sign] ?? null;
	}

	private static function create_prefix_operator(string $sign)
	{
		return self::$prefix_op_symbol_map[$sign] = new Operator($sign, _PREFIX_OP_PRECEDENCES[$sign]);
	}

	private static function create_normal_operator(string $sign)
	{
		return self::$binary_op_symbol_map[$sign] = new Operator($sign, _BINARY_OP_PRECEDENCES[$sign]);
	}
}

