<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IElseAble
{
	public function get_else_branches();
	public function set_else_block(IElseBlock $else): void;
}

interface IElseBlock {}

trait ElseTrait
{
	public IElseBlock|null $else = null;

	public function get_else_branches()
	{
		$items = [];

		$branch = $this->else;
		while ($branch) {
			$items[] = $branch;
			if ($branch instanceof ElseIfBlock) {
				$branch = $branch->else;
			}
			else {
				break;
			}
		}

		return $items;
	}

	public function set_else_block(IElseBlock $else): void
	{
		$this->else = $else;
	}
}

abstract class BaseIfBlock extends BaseControlBlock implements IElseAble, IExceptAble
{
	use ElseTrait;

	public BaseExpression $condition;

	public function __construct(BaseExpression $condition)
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
	use ExceptTrait;
	
	const KIND = 'elseif_block';
}

class ElseBlock extends BaseControlBlock implements IElseBlock
{
	const KIND = 'else_block';
}

// program end
