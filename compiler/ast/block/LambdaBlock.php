<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class LambdaBlock extends BaseBlock implements IEnclosingBlock, ICallableDeclaration, ICallee, IExpression
{
	use FunctionLikeTrait;

	const KIND = 'lambda_block';

	/**
	 * @var PlainIdentifier[]
	 */
	public $use_variables = [];

	public function __construct(?IType $type, ParameterDeclaration ...$parameters)
	{
		$this->type = $type;
		$this->parameters = $parameters;
	}
}
