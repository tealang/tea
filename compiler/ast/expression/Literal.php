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
	// public $is_calling;
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

class LiteralNone extends BaseExpression implements ILiteral
{
	const KIND = 'literal_none';

	public $is_default_value_marker = false;
}

abstract class LiteralString extends BaseExpression implements ILiteral
{
	use LiteralTraitWithValue;
	public $label;
}

class PlainLiteralString extends LiteralString
{
	const KIND = 'plain_literal_string';
}

class EscapedLiteralString extends LiteralString
{
	const KIND = 'escaped_literal_string';
}

class LiteralInteger extends BaseExpression implements ILiteral
{
	use LiteralTraitWithValue;

	const KIND = 'literal_integer';

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

class LiteralFloat extends BaseExpression implements ILiteral
{
	use LiteralTraitWithValue;
	const KIND = 'literal_float';
}

class LiteralBoolean extends BaseExpression implements ILiteral
{
	use LiteralTraitWithValue;
	const KIND = 'bool_literal';
}

class LiteralArray extends ArrayExpression implements ILiteral
{
	use LiteralTrait;
	const KIND = 'literal_array';
}

class LiteralDict extends DictExpression implements ILiteral
{
	use LiteralTrait;
	const KIND = 'literal_dict';
}

// class LiteralObject extends ObjectExpression implements ILiteral
// {
// 	const KIND = 'literal_object';
// }

