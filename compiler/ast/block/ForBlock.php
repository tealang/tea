<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ForBlock extends ControlBlock implements IElseAble, IExceptAble, IBreakAble, IContinueAble
{
	use ElseTrait, ExceptTrait;

	const KIND = 'for_block';

	public $args1;
	public $args2;
	public $args3;

	public function __construct(array $args1, array $args2, array $args3)
	{
		$this->args1 = $args1;
		$this->args2 = $args2;
		$this->args3 = $args3;
	}
}

class ForInBlock extends ControlBlock implements IElseAble, IExceptAble, IBreakAble, IContinueAble
{
	use ElseTrait, ExceptTrait;

	const KIND = 'forin_block';

	public $iterable;
	public $key;
	public $val;

	public function __construct(?ParameterDeclaration $key, ParameterDeclaration $val, BaseExpression $iterable)
	{
		$this->iterable = $iterable;
		$this->key = $key;
		$this->val = $val;
	}
}

class ForToBlock extends ControlBlock implements IElseAble, IExceptAble, IBreakAble, IContinueAble
{
	use ElseTrait, ExceptTrait;

	const KIND = 'forto_block';

	public $key;
	public $val;
	public $start;
	public $end;
	public $step;

	public $is_downto_mode;

	public function __construct(?ParameterDeclaration $key, ParameterDeclaration $val, BaseExpression $start, BaseExpression $end, ?int $step)
	{
		$this->key = $key;
		$this->val = $val;
		$this->start = $start;
		$this->end = $end;
		$this->step = $step;
	}
}

// end
