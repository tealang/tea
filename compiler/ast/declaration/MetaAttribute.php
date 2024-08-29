<?php
namespace Tea;

class MetaAttribute extends Node
{
	public $identifier;

	public $arguments;

	public $group;

	public function __construct(ClassKindredIdentifier $identifier, array $arguments, int $group)
	{
		$this->identifier = $identifier;
		$this->arguments = $arguments;
		$this->group = $group;
	}
}

// end
