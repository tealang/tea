<?php
namespace Tea;

interface IRootDeclaration extends IDeclaration {}

abstract class RootDeclaration extends Node implements IRootDeclaration, IStatement
{
	use DeclarationTrait;

	/**
	 * @var NamespaceIdentifier
	 */
	public $ns;

	public $modifier;

	/**
	 * @var Program
	 */
	public $program;

	public $is_dynamic;

	public function set_namespace(NamespaceIdentifier $ns)
	{
		$this->ns = $ns;
	}

	public function is_root_namespace()
	{
		return $this->program->unit === null || $this->is_runtime;
	}
}

// end
