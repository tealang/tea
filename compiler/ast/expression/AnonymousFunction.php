<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class AnonymousFunction extends BaseExpression implements IScopeBlock
{
	use IScopeBlockTrait;

	const KIND = 'anonymous_function';

	/**
	 * @var PlainIdentifier[]
	 */
	public $use_variables = [];

	// public $mutating_variable_names = [];

	public function __construct(IType $return_type = null, array $parameters = null)
	{
		$this->declared_type = $return_type;
		$this->parameters = $parameters;
	}
}

// class CoroutineBlock extends AnonymousFunction implements IStatement
// {
// 	const KIND = 'coroutine_block';
// }

