<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

interface IBlock
{
	public function add_rebound_variable(VariableDeclaration $decl);
	public function get_rebound_variables(): array;
}

trait IBlockTrait
{
	public $label;

	/**
	 * @var IStatement[] | BaseExpression
	 */
	public $body;

	public $symbols = [];

	public $belong_block;

	public $is_transfered = false;

	private $rebound_variables = [];

	private $rebound_original_types = [];

	public function set_body_with_statements(array $statements)
	{
		$this->body = $statements;
	}

	public function add_rebound_variable(IVariableDeclaration $decl)
	{
		if (!in_array($decl, $this->rebound_variables)) {
			$this->rebound_variables[] = $decl;
			$this->rebound_original_types[] = $decl->get_bound_type();
		}
	}

	public function get_rebound_variables(): array
	{
		return $this->rebound_variables;
	}

	/**
	 * recover original types, and returns the new types
	 */
	public function reset_rebound_types(): array
	{
		$types = [];
		foreach ($this->rebound_variables as $idx => $var) {
			$original_type = $this->rebound_original_types[$idx];
			$types[] = $var->get_bound_type();
			$var->bind_type($original_type);
		}

		return $types;
	}
}

// end
