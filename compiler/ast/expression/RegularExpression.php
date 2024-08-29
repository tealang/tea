<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class RegularExpression extends BaseExpression
{
	const KIND = 'regular_expression';

	public $pattern;
	public $flags;

	public function __construct(string $pattern, string $flags)
	{
		$this->pattern = $pattern;
		$this->flags = $flags;
	}
}
