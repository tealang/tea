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
	use DeclarationTrait;

	const KIND = 'callable_protocol';

	public $async;

	public $parameters;

	public $checking;

	public function __construct(bool $async, ?IType $type, ParameterDeclaration ...$parameters)
	{
		$this->async = $async;
		$this->type = $type;
		$this->parameters = $parameters;
	}

	public function is_accept_type(IType $type)
	{
		return $type === TypeFactory::$_callable;
	}
}
