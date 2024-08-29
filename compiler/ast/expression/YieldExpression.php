<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class YieldExpression extends BaseExpression
{
	const KIND = 'yield_expression';

	public $argument;

	public function __construct(BaseExpression $argument)
	{
		$this->argument = $argument;
	}
}
