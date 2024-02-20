<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface ILiteral {}

trait LiteralTrait
{
	public $is_call_mode;
}

trait LiteralTraitWithValue
{
	use LiteralTrait;

	/**
	 * @var string
	 */
	public $value;

	public function __construct(string $value) {
		$this->value = $value;
	}
}

class NoneLiteral extends BaseExpression implements ILiteral
{
	const KIND = 'none_literal';

	public $is_default_value_marker = false;
}

abstract class StringLiteral extends BaseExpression implements ILiteral
{
	use LiteralTraitWithValue;
	public $label;
}

class UnescapedStringLiteral extends StringLiteral
{
	const KIND = 'unescaped_string_literal';
}

class EscapedStringLiteral extends StringLiteral
{
	const KIND = 'escaped_string_literal';
}

class IntegerLiteral extends BaseExpression implements ILiteral
{
	use LiteralTraitWithValue;

	const KIND = 'integer_literal';

	/**
	 * The string format integer data
	 * e.g. decimal 999
	 * e.g. octal 0777
	 * e.g. hex 0xfff
	 * e.g. binary 0b101010
	 *
	 * @var string
	 */
	// public $value;
}

class FloatLiteral extends BaseExpression implements ILiteral
{
	use LiteralTraitWithValue;
	const KIND = 'float_literal';
}

class BooleanLiteral extends BaseExpression implements ILiteral
{
	use LiteralTraitWithValue;
	const KIND = 'bool_literal';
}

class ArrayLiteral extends ArrayExpression implements ILiteral
{
	use LiteralTrait;
	const KIND = 'array_literal';
}

class DictLiteral extends DictExpression implements ILiteral
{
	use LiteralTrait;
	const KIND = 'dict_literal';
}

// class ObjectLiteral extends ObjectExpression implements ILiteral
// {
// 	const KIND = 'object_literal';
// }

