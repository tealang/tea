<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class RelayExpression extends BaseExpression
{
	const KIND = 'relay_expression';

	public $argument;
	public $callees;

	public function __construct(BaseExpression $argument, BaseExpression ...$callees)
	{
		$this->argument = $argument;
		$this->callees = $callees;
	}
}
