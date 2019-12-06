<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class Parentheses extends Node implements IExpression
{
	const KIND = 'parentheses';

	public $expression;

	public function __construct(IExpression $expression)
	{
		$this->expression = $expression;
	}
}
