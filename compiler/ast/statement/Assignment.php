<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;


interface IAssignable {}
interface IAssignment {}

class Assignment extends BaseStatement implements IAssignment
{
	const KIND = 'normal_assignment';

	public $master;
	public $value;

	public function __construct(IAssignable $master, BaseExpression $value)
	{
		$this->master = $master;
		$this->value = $value;
	}
}

class CompoundAssignment extends BaseStatement implements IAssignment
{
	const KIND = 'compound_assignment';

	public $operator;
	public $master;
	public $value;

	public function __construct(string $operator, IAssignable $master, BaseExpression $value)
	{
		$this->operator = $operator;
		$this->master = $master;
		$this->value = $value;
	}
}

class ArrayElementAssignment extends BaseStatement implements IAssignment
{
	const KIND = 'array_element_assignment';

	public $master;
	public $key;
	public $value;

	public function __construct(BaseExpression $master, ?BaseExpression $key, BaseExpression $value)
	{
		$this->master = $master;
		$this->key = $key;
		$this->value = $value;
	}
}

