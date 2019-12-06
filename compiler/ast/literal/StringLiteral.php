<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class StringLiteral extends Node
{
	use LiteralTraitWithValue;
	public $label;
}

class UnescapedStringLiteral extends StringLiteral implements ILiteral
{
	const KIND = 'unescaped_string_literal';
}

class EscapedStringLiteral extends StringLiteral implements ILiteral
{
	const KIND = 'escaped_string_literal';
}
