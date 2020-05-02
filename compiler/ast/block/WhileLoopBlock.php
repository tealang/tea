<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface ILoopKindredBlock {}

class WhileBlock extends ControlBlock implements IExceptAble, ILoopKindredBlock, IContinueAble
{
	use ExceptTrait;

	const KIND = 'while_block';

	public $condition;

	public $do_the_first;

	public function __construct(BaseExpression $condition)
	{
		$this->condition = $condition instanceof Parentheses ? $condition->expression : $condition;
	}

	public function set_else_block(IElseBlock $else)
	{
		$this->else = $else;
	}
}

class LoopBlock extends ControlBlock implements IExceptAble, ILoopKindredBlock, IContinueAble
{
	use ExceptTrait;

	const KIND = 'loop_block';
}

