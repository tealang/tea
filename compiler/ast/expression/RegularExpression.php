<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class RegularExpression extends BaseExpression
{
	const KIND = 'regular_expression';

	public $pattern;
	public $flags;

	// public $is_calling;

	public function __construct(string $pattern, string $flags)
	{
		$this->pattern = $pattern;
		$this->flags = $flags;
	}
}
