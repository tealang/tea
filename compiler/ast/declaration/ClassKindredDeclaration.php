<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

enum ClassFeature: int
{
	case PROPERTIES = 1;
	case MAGIC_GET = 2;
	case MAGIC_SET = 4;
	case MAGIC_ISSET = 8;
	case MAGIC_UNSET = 16;
	case MAGIC_CALL = 32;
	case MAGIC_CALL_STATIC = 64;
}

abstract class ClassKindredDeclaration extends RootDeclaration
{
	/**
	 * the extends class for classes
	 * @var ClassDeclaration
	 */
	public $extends = [];

	/**
	 * the implements interfaces
	 * @var ClassKindredIdentifier[]
	 */
	public $implements = [];

	/**
	 * @var TraitsUsingStatement[]
	 */
	public $usings = [];

	/**
	 * @var IClassMemberDeclaration[]
	 */
	public $members = [];

	// just for that used traits
	public $trait_members = [];

	/**
	 * the aggregated, actually available members,
	 * including those that inherit from the parent class,
	 * those implemented by default in the interface, and those defined in this class
	 * @var array  [name => Symbol]
	 */
	public $aggregated_members = [];

	/**
	 * The symbols for current class instance
	 * used for this, super
	 */
	public $symbols = [];

	public $belong_block; // aways none

	// the instance type for type Self
	public $typing_identifier;

	public $this_class_symbol;

	public $this_object_symbol;

	public $define_mode;

	public $feature_flags = 0;

	public function __construct(?string $modifier, $name)
	{
		$this->modifier = $modifier;
		$this->name = $name;
	}

	public function unite_feature_flags(int $flags)
	{
		$this->feature_flags |= $flags;
	}

	public function set_feature(ClassFeature $feature)
	{
		$this->feature_flags |= $feature->value;
	}

	public function has_feature(ClassFeature $feature)
	{
		return $this->feature_flags & $feature->value;
	}

	public function is_dynamic(bool $is_weak)
	{
		return $this->is_virtual
			|| ($is_weak && ($this->name === _TYPE_SELF || $this instanceof TraitDeclaration));
	}

	public function set_extends(array $identifiers)
	{
		$this->extends = $identifiers;
	}

	public function set_implements(array $identifiers)
	{
		$this->extends = $identifiers;
	}

	public function append_trait_using(TraitsUsingStatement $using)
	{
		$this->usings[] = $using;
	}

	public function append_member_symbol(Symbol $symbol)
	{
		$name = $symbol->name;
		if (isset($this->symbols[$name])) {
			return false;
		}

		$member = $symbol->declaration;
		$member->belong_block = $this;

		$this->members[$name] = $member;
		$this->symbols[$name] = $symbol;

		return true;
	}

	public function is_same_or_based_with_symbol(Symbol $symbol)
	{
		$symbol_decl = $symbol->declaration;
		return $this === $symbol_decl || $this->find_based_with_symbol($symbol) !== null;
	}

	public function find_based_with_symbol(Symbol $symbol)
	{
		// the bases interfaces
		$bases = $this instanceof InterfaceDeclaration ? $this->extends : $this->implements;

		$result = null;
		foreach ($bases as $based) {
			if ($this->is_identifier_based_with_symbol($based, $symbol)) {
				$result = $based;
				break;
			}
		}

		return $result;
	}

	private static function is_identifier_based_with_symbol(PlainIdentifier $based, Symbol $symbol)
	{
		$based_symbol = $based->symbol;
		$is = $based_symbol === $symbol
			|| $based_symbol->declaration->find_based_with_symbol($symbol);

		return $is;
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
		if ($this->extends and $result = $this->find_based_with_symbol_in_super($this->extends[0], $symbol)) {
			// no any
		}
		else {
			$result = parent::find_based_with_symbol($symbol);
		}

		return $result;
	}

	private function find_based_with_symbol_in_super(PlainIdentifier $super_identifier, Symbol $symbol)
	{
		$super_symbol = $super_identifier->symbol;
		$result = null;

		// symbol in difference packages would be not same, but declaration is
		if ($super_symbol === $symbol || $super_symbol->declaration === $symbol->declaration) {
			$result = $super_identifier;
		}
		elseif ($based_identifier = $super_symbol->declaration->find_based_with_symbol($symbol)) {
			$result = $based_identifier;
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
}

// end
