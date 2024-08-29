<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

trait LiteralTraitWithValue
{
	/**
	 * @var string
	 */
	public $value;

	public function __construct(string $value) {
		$this->value = $value;
	}
}

abstract class LiteralExpression extends BaseExpression
{
	public $is_const_value = true;
}

class LiteralDefaultMark extends LiteralExpression
{
	const KIND = 'literal_default_mark';
}

class LiteralNone extends LiteralExpression
{
	const KIND = 'literal_none';
}

abstract class LiteralString extends LiteralExpression
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

class LiteralInteger extends LiteralExpression
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

class LiteralFloat extends LiteralExpression
{
	use LiteralTraitWithValue;
	const KIND = 'literal_float';
}

class LiteralBoolean extends LiteralExpression
{
	use LiteralTraitWithValue;
	const KIND = 'bool_literal';
}

// end
