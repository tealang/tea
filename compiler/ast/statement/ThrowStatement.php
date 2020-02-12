<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ThrowStatement extends PostConditionAbleStatement
{
	const KIND = 'throw_statement';

	public $argument;

	public function __construct(IExpression $argument)
	{
		$this->argument = $argument;
	}
}
