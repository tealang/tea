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
	/**
	 * the implements interfaces or inherits class
	 * @var ClassKindredIdentifier[]
	 */
	public $bases = [];

	/**
	 * the inherits class for classes
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

	public $this_class_symbol;

	public $this_object_symbol;

	public $define_mode;

	public function __construct(?string $modifier, $name)
	{
		$this->modifier = $modifier;
		$this->name = $name;
	}

	public function set_bases(ClassKindredIdentifier ...$bases)
	{
		$this->bases = $bases;
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
		foreach ($this->bases as $based) {
			$based_symbol = $based->symbol;
			if ($based_symbol === $symbol || $based_symbol->declaration->find_based_with_symbol($symbol) ) {
				$result = $based;
				break;
			}
		}

		return $result;
	}
}

class ClassDeclaration extends ClassKindredDeclaration implements ICallableDeclaration
{
	const KIND = 'class_declaration';

	public $is_abstract;

	public $is_readonly;

	public $is_final;

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
	const KIND = 'type_declaration';
}

class InterfaceDeclaration extends ClassKindredDeclaration
{
	const KIND = 'interface_declaration';
}

class TraitDeclaration extends ClassKindredDeclaration
{
	const KIND = 'trait_declaration';
}

class IntertraitDeclaration extends InterfaceDeclaration
{
	const KIND = 'intertrait_declaration';

	// public $has_default_implementations = false;
}

// end
