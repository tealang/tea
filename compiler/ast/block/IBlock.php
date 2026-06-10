<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

/**
 * @property array $symbols
 * @property IBlock|null $belong_block
 */
interface IBlock
{
	public function add_rebound_variable(IVariableDeclaration $decl);
	public function get_rebound_variables(): array;
	public function set_body_with_statements(array $statements);
	public function set_belong_block(BaseDeclaration|IBlock|null $block): void;
	public function get_label(): ?string;
	public function mark_as_transfered(): void;
	public function has_transfered(): bool;
	public function has_symbol(string $name): bool;
	public function get_symbol(string $name): ?Symbol;
	public function set_symbol(string $name, Symbol $symbol): void;
	public function get_symbols(): array;
	public function get_belong_block(): BaseDeclaration|IBlock|null;
}

trait IBlockTrait
{
	public ?string $label = null;

	/**
	 * @var IStatement[]|BaseExpression
	 */
	public $body;

	/**
	 * @var array<string, mixed>
	 */
	public array $symbols = [];

	/**
	 * The block this block belongs to
	 */
	public BaseDeclaration|IBlock|null $belong_block = null;

	public bool $is_transfered = false;

	/**
	 * @var IVariableDeclaration[]
	 */
	private array $rebound_variables = [];

	/**
	 * @var BaseType[]
	 */
	private array $rebound_original_types = [];

	public function set_body_with_statements(array $statements)
	{
		$this->body = $statements;
	}

	public function get_label(): ?string
	{
		return $this->label;
	}

	public function add_rebound_variable(IVariableDeclaration $decl)
	{
		if (!in_array($decl, $this->rebound_variables, true)) {
			$this->rebound_variables[] = $decl;
			$this->rebound_original_types[] = $decl->get_bound_type();
		}
	}

	public function get_rebound_variables(): array
	{
		return $this->rebound_variables;
	}

	public function mark_as_transfered(): void
	{
		$this->is_transfered = true;
	}

	public function has_transfered(): bool
	{
		return $this->is_transfered;
	}

	public function has_symbol(string $name): bool
	{
		return isset($this->symbols[$name]);
	}

	public function get_symbol(string $name): ?Symbol
	{
		return $this->symbols[$name] ?? null;
	}

	public function set_symbol(string $name, Symbol $symbol): void
	{
		$this->symbols[$name] = $symbol;
	}

	public function get_symbols(): array
	{
		return $this->symbols;
	}

	public function set_belong_block(BaseDeclaration|IBlock|null $block): void
	{
		$this->belong_block = $block;
	}

	public function get_belong_block(): BaseDeclaration|IBlock|null
	{
		return $this->belong_block;
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
