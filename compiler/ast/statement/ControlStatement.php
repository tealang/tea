<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IBreakAble {}
interface IContinueAble {}

abstract class ControlStatement extends BaseStatement
{
	public ?BaseExpression $argument;

	public function __construct(?BaseExpression $argument, IBlock $belong_block)
	{
		$this->argument = $argument;
		$this->belong_block = $belong_block;
		$belong_block->is_transfered = true;
	}
}

abstract class LabeledControlStatement extends ControlStatement
{
	public $target_label;
	public $target_layers;
	public $switch_layers;
}

class BreakStatement extends LabeledControlStatement
{
	const KIND = 'break_statement';
}

class ContinueStatement extends LabeledControlStatement
{
	const KIND = 'continue_statement';
}

class ReturnStatement extends ControlStatement
{
	const KIND = 'return_statement';
}

class ThrowStatement extends ControlStatement
{
	const KIND = 'throw_statement';
}

class ExitStatement extends ControlStatement
{
	const KIND = 'exit_statement';
}

// end
