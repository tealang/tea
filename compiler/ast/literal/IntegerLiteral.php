<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class IntegerLiteral extends Node implements ILiteral
{
	use LiteralTraitWithValue;

	const KIND = 'int_literal';

	/**
	 * The string format integer data
	 * eg. decimal 999
	 * eg. octal 0777
	 * eg. hex 0xfff
	 * eg. binary 0b101010
	 *
	 * @var string
	 */
	// public $value;
}

class UnsignedIntegerLiteral extends IntegerLiteral
{
	const KIND = 'uint_literal';
}

