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
		$belong_block->mark_as_transfered();
	}
}

abstract class LabeledControlStatement extends ControlStatement
{
	/**
	 * Target label name
	 */
	public ?string $target_label = null;

	/**
	 * Number of layers to transfer
	 */
	public int $target_layers = 0;

	/**
	 * Number of switch layers
	 */
	public int $switch_layers = 0;
}

class BreakStatement extends LabeledControlStatement
{
	const KIND = 'break_statement';
}

class ContinueStatement extends LabeledControlStatement
{
	const KIND = 'continue_statement';
}

class GotoStatement extends BaseStatement
{
	const KIND = 'goto_statement';

	public string $target_label;

	public function __construct(string $target_label)
	{
		$this->target_label = $target_label;
	}
}

class LabelStatement extends BaseStatement
{
	const KIND = 'label_statement';

	public string $label;

	public function __construct(string $label)
	{
		$this->label = $label;
	}
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
