<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IExceptAble
{
	public function add_catching_block(CatchBlock $block): void;
	public function set_finally_block(FinallyBlock $block): void;
}

interface IExceptBlock {}

class TryBlock extends BaseControlBlock implements IExceptAble
{
	use ExceptTrait;

	const KIND = 'try_block';

	// /**
	//  * @var CatchBlock
	//  */
	// public $catching_all;
}

class CatchBlock extends BaseControlBlock
{
	const KIND = 'catch_block';

	public ?VariableDeclaration $var;

	public ?BaseType $declared_type;

	public function __construct(?VariableDeclaration $var, ?BaseType $declared_type = null)
	{
		$this->var = $var;
		$this->declared_type = $declared_type;
	}
}

class FinallyBlock extends BaseControlBlock
{
	const KIND = 'finally_block';
}

trait ExceptTrait
{
	/**
	 * @var CatchBlock[]
	 */
	public array $catchings = [];

	public ?FinallyBlock $finally = null;

	public function has_exceptional()
	{
		return $this->catchings || $this->finally;
	}

	public function add_catching_block(CatchBlock $block): void
	{
		$this->catchings[] = $block;
	}

	public function set_finally_block(FinallyBlock $block): void
	{
		$this->finally = $block;
	}
}

// end
