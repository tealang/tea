<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ForInBlock extends ControlBlock implements IElseAble, IExceptAble, IBreakAble, IContinueAble
{
	use ElseTrait, ExceptTrait;

	const KIND = 'forin_block';

	public $iterable;
	public $key_var;
	public $value_var;

	public function __construct(BaseExpression $iterable, ?VariableIdentifier $key_var, VariableIdentifier $value_var)
	{
		$this->iterable = $iterable;
		$this->key_var = $key_var;
		$this->value_var = $value_var;
	}
}

class ForToBlock extends ControlBlock implements IElseAble, IExceptAble, IBreakAble, IContinueAble
{
	use ElseTrait, ExceptTrait;

	const KIND = 'forto_block';

	public $var;
	public $start;
	public $end;
	public $step;

	public $is_downto_mode;

	public function __construct(VariableIdentifier $var, BaseExpression $start, BaseExpression $end, ?int $step)
	{
		$this->var = $var;
		$this->start = $start;
		$this->end = $end;
		$this->step = $step;
	}
}

