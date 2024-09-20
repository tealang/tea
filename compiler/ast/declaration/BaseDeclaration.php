<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IDeclaration {}
interface ICallableDeclaration extends IDeclaration {}
interface IMemberDeclaration extends IDeclaration {}
interface IValuedDeclaration extends IDeclaration {}

trait TypingTrait {

	public $is_virtual;

	/**
	 * @var IType
	 */
	public $declared_type;

	/**
	 * Type that writed in comments
	 * eg. "@var ...", "@return ...", or tailing type notes
	 * @var IType
	 */
	public $noted_type;

	/**
	 * @var IType
	 */
	public $infered_type;

	public function get_hinted_type()
	{
		return $this->noted_type ?? $this->declared_type;
	}

	public function get_type()
	{
		return $this->noted_type ?? $this->declared_type ?? $this->infered_type;
	}

	public function set_type(IType $type)
	{
		if ($this->noted_type) {
			$this->noted_type = $type;
		}
		elseif ($this->declared_type) {
			$this->declared_type = $type;
		}
		else {
			$this->infered_type = $type;
		}
	}
}

trait DeclarationTrait {

	use TypingTrait;

	public $label;

	public $is_runtime;

	/**
	 * @var string
	 */
	public $name;

	public $origin_name;

	// /**
	//  * @var Symbol
	//  */
	// public $symbol;

	public $is_checked = false; // set true when checked by ASTChecker

	// is public or used by other programs
	public $is_unit_level = false;

	public $uses = [];

	public $unknow_identifiers = [];

	public $tailing_newlines;

	public function set_depends_to_unit_level()
	{
		foreach ($this->unknow_identifiers as $identifier) {
			$decl = $identifier->symbol->declaration;
			if (!$decl->is_unit_level) {
				$decl->is_unit_level = true;
				$decl->set_depends_to_unit_level();
			}
		}
	}

	public function append_use_declaration(UseDeclaration $use)
	{
		if (!in_array($use, $this->uses, true)) {
			$this->uses[] = $use;
		}
	}

	public function append_unknow_identifier(PlainIdentifier $identifier)
	{
		$this->unknow_identifiers[] = $identifier;
	}

	public function remove_unknow_identifier(PlainIdentifier $identifier)
	{
		$idx = array_search($identifier, $this->unknow_identifiers, true);
		if ($idx !== false) {
			unset($this->unknow_identifiers[$idx]);
		}
	}

	public function append_unknow_identifiers_from_declaration(IDeclaration $decl)
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

// end
