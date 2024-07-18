<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class InterpolatedString extends BaseExpression
{
	public $items;

	public function __construct(array $items)
	{
		$this->items = $items;
	}
}

class EscapedInterpolatedString extends InterpolatedString
{
	const KIND = 'escaped_interpolated_string';
}

class UnescapedInterpolatedString extends InterpolatedString
{
	const KIND = 'unescaped_interpolated_string';
}

// end
