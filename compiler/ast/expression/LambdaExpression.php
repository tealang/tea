<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class LambdaExpression extends BaseExpression implements IScopeBlock, ICallee
{
	use IScopeBlockTrait;

	const KIND = 'lambda_expression';

	/**
	 * @var PlainIdentifier[]
	 */
	public $use_variables = [];

	// public $mutating_variable_names = [];

	public function __construct(IType $type = null, array $parameters = null)
	{
		$this->type = $type;
		$this->parameters = $parameters;
	}
}

class CoroutineBlock extends LambdaExpression implements IStatement
{
	const KIND = 'coroutine_block';
}

