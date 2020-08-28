<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class ClassKindredDeclaration extends Node implements IRootDeclaration, IStatement
{
	use DeclarationTrait;

	public $ns;

	public $modifier;

	public $is_abstract;

	public $origin_name;

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

	public $super_block; // aways none

	public $this_object_symbol;

	/**
	 * @var Program
	 */
	public $program;

	public function __construct(?string $modifier, $name)
	{
		$this->modifier = $modifier;
		$this->name = $name;
	}

	public function is_root_namespace()
	{
		return $this->program->unit === null || $this->label === _PHP;
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

		$this->members[$member->name] = $member;
		return true;
	}

	public function is_same_or_based_with_symbol(Symbol $symbol)
	{
		return $this->symbol === $symbol || $this->is_based_with_symbol($symbol);
	}

	public function is_based_with_symbol(Symbol $symbol)
	{
		// check the implements interfaces
		foreach ($this->baseds as $interface) {
			if ($interface->symbol === $symbol) {
				return true;
			}

			// if $interface->symbol is null, it should be not checked ast...

			if ($interface->symbol->declaration->is_based_with_symbol($symbol)) {
				return true;
			}
		}

		return false;
	}
}

class ClassDeclaration extends ClassKindredDeclaration implements ICallableDeclaration
{
	const KIND = 'class_declaration';

	// if not a declare mode, set true
	public $define_mode = false;

	public function is_based_with_symbol(Symbol $symbol)
	{
		// check the extends class first
		if ($this->inherits !== null) {
			$inherits_symbol = $this->inherits->symbol;

			// 当 引用的unit中类所继承类 和 当前引用的类 为同一个第三方unit的类时，symbol会有所不同，这时需比较declaration
			if ($inherits_symbol === $symbol || $inherits_symbol->declaration === $symbol->declaration) {
				return true;
			}

			if ($inherits_symbol->declaration->is_based_with_symbol($symbol)) {
				return true;
			}
		}

		return parent::is_based_with_symbol($symbol);
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
	public $has_default_implementations;

	public function append_member(IClassMemberDeclaration $member)
	{
		if ($member instanceof PropertyDeclaration || ($member instanceof FunctionDeclaration && $member->body !== null)) {
			$this->has_default_implementations = true;
		}

		return parent::append_member($member);
	}
}
