<?php
namespace Tea;

class MetaAttribute extends Node
{
	public ClassKindredIdentifier $identifier;

	/**
	 * @var array
	 */
	public array $arguments;

	public int $group;

	public function __construct(ClassKindredIdentifier $identifier, array $arguments, int $group)
	{
		$this->identifier = $identifier;
		$this->arguments = $arguments;
		$this->group = $group;
	}
}

// end
