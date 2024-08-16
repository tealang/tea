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
// interface IAssignment {}

// class Assignment extends BaseStatement implements IAssignment
// {
// 	const KIND = 'assignment';

// 	public $master;
// 	public $value;
// 	public $operator;

// 	public function __construct(IAssignable $master, BaseExpression $value, string $operator)
// 	{
// 		$this->master = $master;
// 		$this->value = $value;
// 		$this->operator = $operator;
// 	}
// }

// class ArrayElementAssignment extends BaseStatement implements IAssignment
// {
// 	const KIND = 'array_element_assignment';

// 	public $master;
// 	public $key;
// 	public $value;

// 	public function __construct(BaseExpression $master, ?BaseExpression $key, BaseExpression $value)
// 	{
// 		$this->master = $master;
// 		$this->key = $key;
// 		$this->value = $value;
// 	}
// }

// end
