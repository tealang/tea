<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IBreakAble {}
interface IContinueAble {}

abstract class PostConditionAbleStatement extends BaseStatement
{
	/**
	 * @BaseExpression
	 */
	public $condition;
}

class BreakStatement extends PostConditionAbleStatement
{
	const KIND = 'break_statement';

	public $argument; // label or break argument

	public $target_label;

	public $target_layers = 0;

	public function __construct(string $argument = null)
	{
		$this->argument = $argument;
	}
}

class ContinueStatement extends BreakStatement
{
	const KIND = 'continue_statement';
	public $switch_layers; // for render PHP code
}

// end
