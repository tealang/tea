<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class UnsetStatement extends BaseStatement
{
	const KIND = 'unset_statement';

	public $argument;

	public function __construct(BaseExpression $argument)
	{
		$this->argument = $argument;
	}
}
