<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IGotoAbleStatement {}
interface IContinueAble {}

abstract class PostConditionAbleStatement extends BaseStatement
{
	/**
	 * @BaseExpression
	 */
	public $condition;
}

class BreakStatement extends PostConditionAbleStatement implements IGotoAbleStatement
{
	const KIND = 'break_statement';

	public $argument; // label or break argument

	public $layer_num;

	public $destination_label;

	public function __construct(string $argument = null, int $layer_num = null)
	{
		$this->argument = $argument;
		$this->layer_num = $layer_num;
	}
}

class ContinueStatement extends BreakStatement implements IGotoAbleStatement
{
	const KIND = 'continue_statement';
}

