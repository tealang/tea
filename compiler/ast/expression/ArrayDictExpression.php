<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

trait MemberContainerTrait
{
	/**
	 * @var array
	 */
	public array $items = [];

	public bool $is_vertical_layout = false; // for render target code

	public function __construct(array $items = [])
	{
		$this->items = $items;
	}
}

interface IArrayLikeExpression {}

class ArrayExpression extends BaseExpression implements IArrayLikeExpression
{
	use MemberContainerTrait;

	const KIND = 'array_expression';
}

class DictExpression extends BaseExpression implements IArrayLikeExpression
{
	use MemberContainerTrait;

	const KIND = 'dict_expression';
}

class DictMember extends Node
{
	const KIND = 'dict_member';

	public BaseExpression $key;
	public BaseExpression $value;

	public function __construct(BaseExpression $key, BaseExpression $value)
	{
		$this->key = $key;
		$this->value = $value;
	}
}

// end
