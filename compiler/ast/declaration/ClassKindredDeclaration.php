<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

enum ClassFeature: int
{
	case ITERATOR = 0x1;
	case ARRAY_ACCESS = 0x2;
	case DYNAMIC_PROPERTIES = 0x4;
	case MAGIC_GET = 0x100;
	case MAGIC_SET = 0x200;
	case MAGIC_ISSET = 0x400;
	case MAGIC_UNSET = 0x800;
	case MAGIC_CALL = 0x1000;
	case MAGIC_CALL_STATIC = 0x2000;
}

abstract class ClassKindredDeclaration extends RootDeclaration
{
	/**
	 * the extends class for classes
	 * @var ClassKindredIdentifier[]
	 */
	public array $extends = [];

	/**
	 * the implements interfaces
	 * @var ClassKindredIdentifier[]
	 */
	public array $implements = [];

	/**
	 * @var TraitsUsingStatement[]
	 */
	public array $usings = [];

	/**
	 * @var IClassMemberDeclaration[]
	 */
	public array $members = [];

	/**
	 * The symbols for current class instance
	 * used for this, super
	 * @var array<string, mixed>
	 */
	public array $symbols = [];

	/**
	 * The block this declaration belongs to (always none for class kindred)
	 */
	public BaseDeclaration|IBlock|null $belong_block = null;

	// the instance type for type Self
	public ?TypeReference $typing_identifier = null;

	public ?Symbol $this_class_symbol = null;

	public ?Symbol $this_object_symbol = null;

	public ?int $define_mode = null;

	public int $feature_flags = 0;

	public bool $is_anonymous = false;

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

	public function set_extends(array $identifiers)
	{
		$this->extends = $identifiers;
	}

	public function set_implements(array $identifiers)
	{
		$this->implements = $identifiers;
	}

	public function append_trait_using(TraitsUsingStatement $using)
	{
		$this->usings[] = $using;
	}

	public function append_member_symbol(Symbol $symbol)
	{
		$name = $symbol->name;
		$member = $symbol->declaration;
		$existing = $this->symbols[$name] ?? null;
		if ($existing !== null) {
			$existing_member = $existing->declaration;
			if ($existing_member instanceof PropertyDeclaration && $member instanceof MethodDeclaration) {
				$property_key = self::get_property_symbol_key($name);
				$this->symbols[$property_key] = $existing;
				$this->members[$property_key] = $existing_member;
				unset($this->symbols[$name], $this->members[$name]);
			}
			elseif ($existing_member instanceof MethodDeclaration && $member instanceof PropertyDeclaration) {
				$name = self::get_property_symbol_key($name);
			}
			else {
				return false;
			}
		}

		$member->belong_block = $this;

		$this->members[$name] = $member;
		$this->symbols[$name] = $symbol;

		return true;
	}

	public static function get_property_symbol_key(string $name): string
	{
		return 'property:' . $name;
	}

	public function is_same_or_based_with_symbol(Symbol $symbol)
	{
		return TypeHelper::is_classkindred_same_or_based_with_symbol($this, $symbol);
	}

	public function find_based_with_symbol(Symbol $symbol)
	{
		return TypeHelper::find_classkindred_based_with_symbol($this, $symbol);
	}
}

class ClassDeclaration extends ClassKindredDeclaration implements ICallableDeclaration
{
	const KIND = 'class_declaration';

	public bool $is_abstract = false;

	public bool $is_readonly = false;

	public bool $is_final = false;

	public function find_based_with_symbol(Symbol $symbol)
	{
		return TypeHelper::find_classkindred_based_with_symbol($this, $symbol);
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
