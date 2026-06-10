<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class MatchBlock extends BaseExpression implements IBlock
{
	use IBlockTrait;

	const KIND = 'match_block';

	public BaseExpression $subject;

	/**
	 * @var MatchArm[]
	 */
	public array $arms;

	public ?BaseType $value_type;

	public function __construct(BaseExpression $subject)
	{
		$this->subject = $subject instanceof Parentheses ? $subject->expression : $subject;
	}

	public function set_arms(array $arms)
	{
		$this->arms = $arms;
	}
}

class MatchArm extends BaseExpression
{
	const KIND = 'match_arm';

	/**
	 * @var BaseExpression[]
	 */
	public array $patterns;

	public BaseExpression $return;

	public function __construct(array $patterns)
	{
		$this->patterns = $patterns;
	}
}

// end
