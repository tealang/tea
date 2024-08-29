<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class Parentheses extends BaseExpression
{
	const KIND = 'parentheses';

	public $expression;

	public function __construct(BaseExpression $expression)
	{
		$this->expression = $expression;
	}
}
