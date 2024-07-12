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
	public $key;
	public $val;

	public function __construct(?VariableIdentifier $key, VariableIdentifier $val, BaseExpression $iterable)
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

	public function __construct(?VariableIdentifier $key, VariableIdentifier $val, BaseExpression $start, BaseExpression $end, ?int $step)
	{
		$this->key = $key;
		$this->val = $val;
		$this->start = $start;
		$this->end = $end;
		$this->step = $step;
	}
}

