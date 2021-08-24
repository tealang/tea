<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class StringInterpolation extends BaseExpression
{
	public $items;

	public function __construct(array $items)
	{
		$this->items = $items;
	}
}

class EscapedStringInterpolation extends StringInterpolation
{
	const KIND = 'escaped_string_interpolation';
}

class UnescapedStringInterpolation extends StringInterpolation
{
	const KIND = 'unescaped_string_interpolation';
}

// end
