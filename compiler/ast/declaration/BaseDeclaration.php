<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IDeclaration {
	public function get_name(): ?string;
}

interface ICallableDeclaration {
	// public function get_parameters(): array;
}

interface IValuableDeclaration {
	public function get_expressed_type(): BaseType;
}

interface IUnknownIdentifierContainer {
	public function append_unknow_identifier(PlainIdentifier|TypeReference $identifier);
	public function remove_unknow_identifier(PlainIdentifier|TypeReference $identifier);
}

trait TypingTrait {

	public bool $is_virtual = false;

	public ?BaseType $declared_type = null;

	public ?BaseType $noted_type = null;

	public ?string $noted_type_source = null;

	public bool $noted_type_from_phpdoc = false;

	public bool $noted_type_nullable_inherited = false;

	public ?BaseType $infered_type = null;

	public function get_hinted_type(): BaseType
	{
		return $this->noted_type ?? $this->declared_type ?? TypeFactory::$_any;
	}

	public function get_expressed_type(): BaseType
	{
		return $this->noted_type ?? $this->declared_type ?? $this->infered_type ?? TypeFactory::$_any;
	}

	public function get_bound_type(): BaseType
	{
		return TypeHelper::get_bound_type($this);
	}

	public function bind_type(BaseType $type)
	{
		TypeHelper::set_raw_bound_type($this, $type);
	}
}

trait BaseDeclarationTrait {

	use TypingTrait;

	// public $label;

	/**
	 * @var string|null
	 */
	public ?string $modifier = null;

	/**
	 * @var string
	 */
	public string $name;

	public ?string $origin_name = null;

	// /**
	//  * @var Symbol
	//  */
	// public $symbol;

	public bool $is_extern = false;

	// is public or used by other programs
	public bool $is_unit_level = false;

	/**
	 * @var UseDeclaration[]
	 */
	public array $uses = [];

	/**
	 * PHP 8 attributes / Tea meta attributes attached to this declaration
	 * @var MetaAttribute[]
	 */
	public array $attributes = [];

	/**
	 * @var array<int, PlainIdentifier|TypeReference>
	 */
	public array $unknow_identifiers = [];

	/**
	 * The block this declaration belongs to
	 */
	public BaseDeclaration|IBlock|null $belong_block = null;

	/**
	 * The program this declaration belongs to
	 */
	public ?Program $program = null;

	public function get_name(): ?string
	{
		return $this->name;
	}

	public function set_depends_to_unit_level()
	{
		ASTHelper::set_depends_to_unit_level($this);
	}

	public function append_use_declaration(UseDeclaration $use)
	{
		if (!in_array($use, $this->uses, true)) {
			$this->uses[] = $use;
		}
	}

	public function append_unknow_identifier(PlainIdentifier|TypeReference $identifier)
	{
		$this->unknow_identifiers[] = $identifier;
	}

	public function remove_unknow_identifier(PlainIdentifier|TypeReference $identifier)
	{
		$idx = array_search($identifier, $this->unknow_identifiers, true);
		if ($idx !== false) {
			unset($this->unknow_identifiers[$idx]);
		}
	}

	public function append_unknow_identifiers_from_declaration(BaseDeclaration $decl)
	{
		if (!$decl->unknow_identifiers) {
			return;
		}

		$this->unknow_identifiers = array_merge(
			$this->unknow_identifiers,
			$decl->unknow_identifiers
		);
	}
}

abstract class BaseDeclaration extends Node implements IDeclaration, IUnknownIdentifierContainer
{
	use BaseDeclarationTrait;
}

abstract class RootDeclaration extends BaseDeclaration implements IStatement
{
	public ?NamespaceIdentifier $ns = null;

	public ?Program $program = null;

	public function set_namespace(NamespaceIdentifier $ns)
	{
		$this->ns = $ns;
	}

	public function is_root_namespace()
	{
		return $this->program->unit === null || $this->is_extern;
	}
}

// end
