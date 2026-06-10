<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class SwitchBlock extends BaseControlBlock implements IElseAble, IExceptAble, IBreakAble
{
	use ElseTrait, ExceptTrait;

	const KIND = 'switch_block';

	public BaseExpression $subject;

	/**
	 * @var SwitchBranch[]
	 */
	public array $branches;

	public function __construct(BaseExpression $subject)
	{
		$this->subject = $subject instanceof Parentheses ? $subject->expression : $subject;
	}

	public function set_branches(array $branches)
	{
		$this->branches = $branches;
	}
}

class SwitchBranch extends BaseControlBlock
{
	const KIND = 'switch_branch';

	/**
	 * @var BaseExpression[]
	 */
	public array $patterns;

	public function __construct(array $patterns)
	{
		$this->patterns = $patterns;
	}
}

// end
