<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class WhileLikeBlock extends ControlBlock implements IExceptAble, IBreakAble, IContinueAble
{
	use ExceptTrait;

	public $condition;

	public function set_else_block(IElseBlock $else)
	{
		$this->else = $else;
	}
}

class WhileBlock extends WhileLikeBlock
{
	const KIND = 'while_block';

	public function __construct(BaseExpression $condition)
	{
		$this->condition = $condition;
	}
}

class DoWhileBlock extends WhileLikeBlock
{
	const KIND = 'do_while_block';
}

class LoopBlock extends ControlBlock implements IExceptAble, IBreakAble, IContinueAble
{
	use ExceptTrait;

	const KIND = 'loop_block';
}

