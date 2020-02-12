<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IExceptAble {}
interface IExceptBlock {}

class TryBlock extends BaseBlock implements IExceptAble
{
	use ExceptTrait;

	const KIND = 'try_block';
}

class CatchBlock extends BaseBlock implements IExceptBlock, IExceptAble
{
	use ExceptTrait;

	const KIND = 'catch_block';

	public $var;

	public function __construct(VariableDeclaration $var)
	{
		$this->var = $var;
	}
}

class FinallyBlock extends BaseBlock implements IExceptBlock
{
	const KIND = 'finally_block';
}

trait ExceptTrait
{
	public $except;

	public function set_except_block(IExceptBlock $except)
	{
		$this->except = $except;
	}
}

