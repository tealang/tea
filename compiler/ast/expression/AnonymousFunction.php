<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class AnonymousFunction extends BaseExpression implements IScopeBlock
{
	use IScopeBlockTrait;

	const KIND = 'anonymous_function';

	/**
	 * @var ParameterDeclaration[]
	 */
	public $using_params = [];

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

