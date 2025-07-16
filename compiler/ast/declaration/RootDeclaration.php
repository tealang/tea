<?php
namespace Tea;

interface IRootDeclaration extends IDeclaration, IStatement {}

trait IRootDeclarationTrait
{
	/**
	 * @var NamespaceIdentifier
	 */
	public $ns;

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
