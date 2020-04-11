<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IElseAble {}
interface IElseBlock {}

trait ElseTrait
{
	public $else;

	public function set_else_block(IElseBlock $else)
	{
		$this->else = $else;
	}
}

abstract class BaseIfBlock extends ControlBlock implements IElseAble, IExceptAble
{
	use ElseTrait;

	public $condition;

	public function __construct(IExpression $condition)
	{
		$this->condition = $condition instanceof Parentheses ? $condition->expression : $condition;
	}
}

class IfBlock extends BaseIfBlock implements IStatement
{
	use ExceptTrait;

	const KIND = 'if_block';
}

class ElseIfBlock extends BaseIfBlock implements IElseBlock
{
	const KIND = 'elseif_block';
}

class ElseBlock extends ControlBlock implements IElseBlock
{
	const KIND = 'else_block';
}

// program end
