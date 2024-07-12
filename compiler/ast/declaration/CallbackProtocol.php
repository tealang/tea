<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class CallbackProtocol extends Node implements ICallableDeclaration, IVariableDeclaration
{
	use DeclarationTrait;

	const KIND = 'callback_protocol';

	public $async;

	public $parameters;

	public $is_checking;

	public function __construct(bool $async, string $name, ?IType $type, ParameterDeclaration ...$parameters)
	{
		$this->async = $async;
		$this->name = $name;
		$this->hinted_type = $type;
		$this->parameters = $parameters;
	}
}
