<?php
namespace Tea;

interface IRootDeclaration {}

abstract class RootDeclaration extends BaseDeclaration implements IRootDeclaration, IStatement
{
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
		return $this->program->unit === null || $this->is_extern;
	}
}

// end
