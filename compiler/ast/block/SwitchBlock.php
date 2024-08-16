<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class SwitchBlock extends ControlBlock implements IElseAble, IExceptAble, IBreakAble
{
	use ElseTrait, ExceptTrait;

	const KIND = 'switch_block';

	public $test;

	/**
	 * @var CaseBranch[]
	 */
	public $branches;

	public function __construct(BaseExpression $test)
	{
		$this->test = $test instanceof Parentheses ? $test->expression : $test;
	}

	public function set_branches(array $branches)
	{
		$this->branches = $branches;
	}
}

class CaseBranch extends ControlBlock
{
	const KIND = 'case_branch';

	/**
	 * @var BaseExpression[]
	 */
	public $rule_arguments;

	public function __construct(array $rule_arguments)
	{
		$this->rule_arguments = $rule_arguments;
	}
}

// end
