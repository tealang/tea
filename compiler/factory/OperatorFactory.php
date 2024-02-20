<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class OperatorFactory
{
	static $cast;
	static $concat;
	static $array_concat;
	static $none_coalescing;
	static $ternary; // exp0 ? exp1 : exp2, or exp0 ?: exp1

	static $exponentiation;
	static $identity;
	static $negation;
	static $pre_increment;
	static $post_increment;
	static $pre_decrement;
	static $post_decrement;
	static $addition;
	static $subtraction;
	static $multiplication;
	static $division;
	static $remainder;
	static $spaceship;

	static $bitwise_not;
	static $bitwise_and;
	static $bitwise_xor;
	static $bitwise_or;
	static $shift_left;
	static $shift_right;

	static $lessthan;
	static $greatethan;
	static $lessthan_or_equal;
	static $greatethan_or_equal;
	static $equal;
	static $identical;
	static $not_equal;
	static $not_identical;
	static $is;
	static $bool_not;
	static $bool_and;
	// static $bool_xor;
	static $bool_or;
	static $low_bool_and;
	static $low_bool_xor;
	static $low_bool_or;

	private static $operator_map = [];

	private static $tea_prefix_map = [];
	private static $tea_postfix_map = [];
	private static $tea_normal_map = [];

	private static $php_prefix_map = [];
	private static $php_postfix_map = [];
	private static $php_normal_map = [];

	private static $number_operators;
	private static $bitwise_operators;
	private static $bool_operators;

	public static function init()
	{
		self::create_operator(OPID::NEW);
		self::create_operator(OPID::CLONE);

		self::create_operator(OPID::DOT);
		self::$cast = self::create_operator(OPID::CAST);
		self::create_operator(OPID::PIPE);

		self::create_operator(OPID::REFERENCE);
		self::$identity = self::create_operator(OPID::IDENTITY);
		self::$negation = self::create_operator(OPID::NEGATION);
		self::$bitwise_not = self::create_operator(OPID::BITWISE_NOT);
		self::$bool_not = self::create_operator(OPID::BOOL_NOT);

		self::$pre_increment = self::create_operator(OPID::PRE_INCREMENT);
		self::$post_increment = self::create_operator(OPID::PRE_DECREMENT);
		self::$pre_decrement = self::create_operator(OPID::POST_INCREMENT);
		self::$post_decrement = self::create_operator(OPID::POST_DECREMENT);

		self::$exponentiation = self::create_operator(OPID::EXPONENTIATION);

		self::$multiplication = self::create_operator(OPID::MULTIPLICATION);
		self::$division = self::create_operator(OPID::DIVISION);
		self::$remainder = self::create_operator(OPID::REMAINDER);

		self::$addition = self::create_operator(OPID::ADDITION);
		self::$subtraction = self::create_operator(OPID::SUBTRACTION);

		self::$concat = self::create_operator(OPID::CONCAT);
		self::$array_concat = self::create_operator(OPID::ARRAY_CONCAT);
		self::create_operator(OPID::ARRAY_UNION);

		self::$shift_left = self::create_operator(OPID::SHIFT_LEFT);
		self::$shift_right = self::create_operator(OPID::SHIFT_RIGHT);

		self::$is = self::create_operator(OPID::IS);
		self::$equal = self::create_operator(OPID::EQUAL);
		self::$identical = self::create_operator(OPID::IDENTICAL);
		self::$not_equal = self::create_operator(OPID::NOT_EQUAL);
		self::$not_identical = self::create_operator(OPID::NOT_IDENTICAL);
		self::$lessthan = self::create_operator(OPID::LESSTHAN);
		self::$greatethan = self::create_operator(OPID::GREATERTHAN);
		self::$lessthan_or_equal = self::create_operator(OPID::LESSTHAN_OR_EQUAL);
		self::$greatethan_or_equal = self::create_operator(OPID::GREATERTHAN_OR_EQUAL);
		self::$spaceship = self::create_operator(OPID::SPACESHIP);

		self::$bool_and = self::create_operator(OPID::BOOL_AND);
		// self::$bool_xor = self::create_operator(OPID::BOOL_XOR);
		self::$bool_or = self::create_operator(OPID::BOOL_OR);

		self::$bitwise_and = self::create_operator(OPID::BITWISE_AND);
		self::$bitwise_xor = self::create_operator(OPID::BITWISE_XOR);
		self::$bitwise_or = self::create_operator(OPID::BITWISE_OR);

		self::$none_coalescing = self::create_operator(OPID::NONE_COALESCING);
		self::$ternary = self::create_operator(OPID::TERNARY);

		self::create_operator(OPID::ASSIGNMENT);

		self::create_operator(OPID::YIELD_FROM);
		self::create_operator(OPID::YIELD);
		self::create_operator(OPID::PRINT);

		self::$low_bool_and = self::create_operator(OPID::LOW_BOOL_AND);
		self::$low_bool_xor = self::create_operator(OPID::LOW_BOOL_XOR);
		self::$low_bool_or = self::create_operator(OPID::LOW_BOOL_OR);

		self::make_groups();
		self::prepare_for_tea();
		self::prepare_for_php();
	}

	private static function make_groups()
	{
		// number
		self::$number_operators = [
			self::$exponentiation,
			self::$identity,
			self::$negation,
			self::$pre_increment,
			self::$post_increment,
			self::$pre_decrement,
			self::$post_decrement,
			self::$addition,
			self::$subtraction,
			self::$multiplication,
			self::$division,
			self::$remainder,
			self::$spaceship
		];

		// bitwise
		self::$bitwise_operators = [
			self::$bitwise_not,
			self::$bitwise_and,
			self::$bitwise_xor,
			self::$bitwise_or,
			self::$shift_left,
			self::$shift_right
		];

		// bool
		self::$bool_operators = [
			self::$lessthan,
			self::$greatethan,
			self::$lessthan_or_equal,
			self::$greatethan_or_equal,
			self::$equal,
			self::$identical,
			self::$not_equal,
			self::$not_identical,
			self::$is,
			self::$bool_not,
			self::$bool_and,
			// self::$bool_xor,
			self::$bool_or,
			self::$low_bool_and,
			self::$low_bool_xor,
			self::$low_bool_or,
		];
	}

	public static function is_number_operator(Operator $operator)
	{
		return in_array($operator, self::$number_operators, true);
	}

	public static function is_bitwise_operator(Operator $operator)
	{
		return in_array($operator, self::$bitwise_operators, true);
	}

	public static function is_bool_operator(Operator $operator)
	{
		return in_array($operator, self::$bool_operators, true);
	}

	public static function get_tea_prefix_operator(?string $sign)
	{
		return self::$tea_prefix_map[$sign] ?? null;
	}

	public static function get_tea_postfix_operator(?string $sign)
	{
		return self::$tea_postfix_map[$sign] ?? null;
	}

	public static function get_tea_normal_operator(?string $sign)
	{
		return self::$tea_normal_map[$sign] ?? null;
	}

	public static function get_php_prefix_operator(?string $sign)
	{
		return self::$php_prefix_map[$sign] ?? null;
	}

	public static function get_php_postfix_operator(?string $sign)
	{
		return self::$php_postfix_map[$sign] ?? null;
	}

	public static function get_php_normal_operator(?string $sign)
	{
		return self::$php_normal_map[$sign] ?? null;
	}

	private static function prepare_for_tea()
	{
		foreach (TeaSyntax::OPERATORS as $prec => $group) {
			foreach ($group as $id => $opt) {
				$operator = self::$operator_map[$id] ?? null;
				if ($operator === null) {
					throw new Exception("Unknow operator id {$id}");
				}

				$sign = $opt[0];
				$type = $opt[1];
				$operator->tea_sign = $sign;
				$operator->tea_assoc = $opt[2];
				$operator->tea_prec = $prec;

				switch ($type) {
					case OP_PRE:
						self::$tea_prefix_map[$sign] = $operator;
						break;
					case OP_POST:
						self::$tea_postfix_map[$sign] = $operator;
						break;
					case OP_BIN:
					case OP_TERNARY:
						self::$tea_normal_map[$sign] = $operator;
						break;
					default:
						throw new Exception("Unknow operator type $type");
				}
			}
		}
	}

	private static function prepare_for_php()
	{
		foreach (PHPSyntax::OPERATORS as $prec => $group) {
			foreach ($group as $id => $opt) {
				$operator = self::$operator_map[$id] ?? null;
				if ($operator === null) {
					throw new Exception("Unknow operator id {$id}");
				}

				$sign = $opt[0];
				$type = $opt[1];
				$operator->php_sign = $sign;
				$operator->php_assoc = $opt[2];
				$operator->php_prec = $prec;

				switch ($type) {
					case OP_PRE:
						self::$php_prefix_map[$sign] = $operator;
						break;
					case OP_POST:
						self::$php_postfix_map[$sign] = $operator;
						break;
					case OP_BIN:
					case OP_TERNARY:
						self::$php_normal_map[$sign] = $operator;
						break;
					default:
						throw new Exception("Unknow operator type $type");
				}
			}
		}
	}

	private static function create_operator(int $id)
	{
		$op = new Operator($id);
		self::$operator_map[$id] = $op;
		return $op;
	}
}

// end
