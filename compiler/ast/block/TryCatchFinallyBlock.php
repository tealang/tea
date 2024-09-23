<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IExceptAble {}
interface IExceptBlock {}

class TryBlock extends ControlBlock implements IExceptAble
{
	use ExceptTrait;

	const KIND = 'try_block';

	// /**
	//  * @var CatchBlock
	//  */
	// public $catching_all;
}

class CatchBlock extends ControlBlock
{
	const KIND = 'catch_block';

	public $var;

	public function __construct(VariableDeclaration $var)
	{
		$this->var = $var;
	}
}

class FinallyBlock extends ControlBlock
{
	const KIND = 'finally_block';
}

trait ExceptTrait
{
	public $catchings = [];

	public $finally;

	public function has_exceptional()
	{
		return $this->catchings || $this->finally;
	}

	public function add_catching_block(CatchBlock $block)
	{
		$this->catchings[] = $block;
	}

	public function set_finally_block(FinallyBlock $block)
	{
		$this->finally = $block;
	}
}

// end
