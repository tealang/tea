<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class AnonymousFunction extends BaseExpression implements IDeclaration, IFunctionDeclaration, IUnknownIdentifierContainer
{
	use BaseDeclarationTrait, FunctionTrait;

	const KIND = 'anonymous_function';

	/**
	 * @var ParameterDeclaration[]
	 */
	public array $using_params = [];

	public bool $is_static = false;

	public function __construct(?BaseType $return_type = null, ?array $parameters = null)
	{
		$this->declared_type = $return_type;
		$this->parameters = $parameters ?? [];
	}
}

// class CoroutineBlock extends AnonymousFunction implements IStatement
// {
// 	const KIND = 'coroutine_block';
// }
