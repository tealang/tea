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

	public BaseExpression $argument;
	
	public bool $is_from = false;

	public function __construct(BaseExpression $argument, bool $is_from = false)
	{
		$this->argument = $argument;
		$this->is_from = $is_from;
	}
}

class ThrowExpression extends BaseExpression
{
	const KIND = 'throw_expression';

	public BaseExpression $argument;

	public function __construct(BaseExpression $argument)
	{
		$this->argument = $argument;
	}
}
