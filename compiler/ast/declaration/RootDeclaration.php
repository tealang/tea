<?php
namespace Tea;

interface IRootDeclaration extends IDeclaration {}

abstract class RootDeclaration extends Node implements IRootDeclaration, IStatement
{
	use DeclarationTrait;

	public $origin_name;

	/**
	 * @var NamespaceIdentifier
	 */
	public $ns;

	public $modifier;

	/**
	 * @var Program
	 */
	public $program;

	public function set_namespace(NamespaceIdentifier $ns)
	{
		$this->ns = $ns;
	}

	public function is_root_namespace()
	{
		return $this->program->unit === null || $this->label === _PHP;
	}
}

// end
