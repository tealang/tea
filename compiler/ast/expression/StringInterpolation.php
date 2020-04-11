<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class EscapedStringInterpolation extends BaseExpression
{
	const KIND = 'escaped_string_interpolation';

	public $items;

	public function __construct(array $items)
	{
		$this->items = $items;
	}
}

class UnescapedStringInterpolation extends EscapedStringInterpolation
{
	const KIND = 'unescaped_string_interpolation';
}
