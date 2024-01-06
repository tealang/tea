<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class ClassKindredDeclaration extends RootDeclaration
{
	public $is_abstract;

	/**
	 * the implements interfaces or inherits class
	 * @var ClassKindredIdentifier[]
	 */
	public $baseds = [];

	/**
	 * the inherits class for ClassDeclaration
	 * @var ClassDeclaration
	 */
	public $inherits;

	/**
	 * @var IClassMemberDeclaration[]
	 */
	public $members = [];

	/**
	 * 聚合的，实际可用的成员，包括继承父类的、接口中默认实现的，和本类中定义的
	 * @var array  [name => IClassMemberDeclaration]
	 */
	public $aggregated_members = [];

	/**
	 * The symbols for current class instance
	 * used for this, super
	 */
	// public $symbols = [];

	public $belong_block; // aways none

	public $this_object_symbol;

	public function __construct(?string $modifier, $name)
	{
		$this->modifier = $modifier;
		$this->name = $name;
	}

	public function set_baseds(ClassKindredIdentifier ...$baseds)
	{
		$this->baseds = $baseds;
	}

	public function append_member(IClassMemberDeclaration $member)
	{
		if (isset($this->members[$member->name])) {
			return false;
		}

		$member->belong_block = $this;
		$this->members[$member->name] = $member;

		return true;
	}

	public function is_same_or_based_with_symbol(Symbol $symbol)
	{
		return $this->symbol === $symbol || $this->find_based_with_symbol($symbol) !== null;
	}

	public function find_based_with_symbol(Symbol $symbol)
	{
		$result = null;

		// check the implements interfaces
		foreach ($this->baseds as $interface) {
			if ($interface->symbol === $symbol || $interface->symbol->declaration->find_based_with_symbol($symbol) ) {
				$result = $interface;
				break;
			}
		}

		return $result;
	}
}

class ClassDeclaration extends ClassKindredDeclaration implements ICallableDeclaration
{
	const KIND = 'class_declaration';

	// if not declare mode, set true
	public $define_mode = false;

	public function find_based_with_symbol(Symbol $symbol)
	{
		// check the extends class first
		if ($this->inherits !== null and $result = $this->find_based_with_symbol_in_inherits($this->inherits, $symbol)) {
			// no any
		}
		else {
			$result = parent::find_based_with_symbol($symbol);
		}

		return $result;
	}

	private function find_based_with_symbol_in_inherits($inherits, $symbol)
	{
		$inherits_symbol = $inherits->symbol;
		$result = null;

		// 当引用的unit中类所继承类 和 当前引用的类 为同一个第三方unit的类时，symbol会有所不同，这时需比较declaration
		if ($inherits_symbol === $symbol || $inherits_symbol->declaration === $symbol->declaration) {
			$result = $inherits;
		}
		elseif ($identifier = $inherits_symbol->declaration->find_based_with_symbol($symbol)) {
			$result = $identifier;
		}

		return $result;
	}
}

class BuiltinTypeClassDeclaration extends ClassDeclaration
{
	const KIND = 'class_declaration';
}

class InterfaceDeclaration extends ClassKindredDeclaration
{
	const KIND = 'interface_declaration';

	/**
	 * 是否有默认实现的成员
	 * @var bool
	 */
	public $has_default_implementations = false;
}

// end
