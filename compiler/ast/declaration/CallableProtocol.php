<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class CallableProtocol extends Node implements ICallableDeclaration, IType
{
	const KIND = 'callable_protocol';

	/**
	 * @var BaseType
	 */
	public $type;

	public $parameters;

	public function __construct(?IType $type, ParameterDeclaration ...$parameters)
	{
		$this->type = $type;
		$this->parameters = $parameters;
	}

	public function is_accept_type(IType $type)
	{
		return $type === TypeFactory::$_callable || $type === TypeFactory::$_none;
	}
}
