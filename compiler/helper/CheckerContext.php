<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class CheckerContext
{
	private static array $checked_nodes = [];
	private static array $checking_nodes = [];
	private static array $preprocessed_classkindreds = [];

	private bool $is_weakly_checking;
	private ?ASTFactory $factory;
	private Unit $unit;
	private ?Unit $builtin_unit;
	private ?Program $program = null;
	private ?IBlock $block = null;
	private ?IFunctionDeclaration $function = null;
	private bool $allow_none_coalescing_key_access = false;
	private bool $allow_php_soft_key_access = false;
	private array $check_warnings = [];

	public static function reset_check_state(): void
	{
		self::$checked_nodes = [];
		self::$checking_nodes = [];
		self::$preprocessed_classkindreds = [];
	}

	public function __construct(bool $is_weakly_checking, Unit $unit, ?Unit $builtin_unit = null)
	{
		$this->is_weakly_checking = $is_weakly_checking;
		$this->unit = $unit;
		$this->builtin_unit = $builtin_unit;
		$this->factory = $unit->factory;
	}

	public function is_weakly_checking(): bool
	{
		return $this->is_weakly_checking;
	}

	public function get_factory(): ?ASTFactory
	{
		return $this->factory;
	}

	public function get_unit(): Unit
	{
		return $this->unit;
	}

	public function get_builtin_unit(): ?Unit
	{
		return $this->builtin_unit;
	}

	public function set_program(Program $program): void
	{
		$this->program = $program;
	}

	public function get_program(): Program
	{
		return $this->program;
	}

	public function set_block(?IBlock $block): void
	{
		$this->block = $block;
	}

	public function get_block(): ?IBlock
	{
		return $this->block;
	}

	public function set_function(?IFunctionDeclaration $function): void
	{
		$this->function = $function;
	}

	public function get_function(): ?IFunctionDeclaration
	{
		return $this->function;
	}

	public function snapshot_variable_bindings(IBlock $block): array
	{
		$bindings = [];
		$current = $block;
		while ($current instanceof IBlock) {
			foreach ($current->get_symbols() as $symbol) {
				$decl = $symbol->declaration ?? null;
				if ($decl instanceof IVariableDeclaration) {
					$bindings[spl_object_id($decl)] = [$decl, TypeHelper::get_raw_bound_type($decl)];
				}
			}

			$current = $current->get_belong_block();
		}

		return $bindings;
	}

	public function restore_variable_bindings(array $bindings): void
	{
		foreach ($bindings as [$decl, $type]) {
			TypeHelper::set_raw_bound_type($decl, $type);
		}
	}

	public function is_checked(object $node): bool
	{
		$key = spl_object_id($node);
		return isset(self::$checked_nodes[$key]) && self::$checked_nodes[$key] === $node;
	}

	public function mark_checked(object $node): void
	{
		self::$checked_nodes[spl_object_id($node)] = $node;
	}

	public function is_checking(object $node): bool
	{
		$key = spl_object_id($node);
		return isset(self::$checking_nodes[$key]) && self::$checking_nodes[$key] === $node;
	}

	public function mark_checking(object $node): void
	{
		self::$checking_nodes[spl_object_id($node)] = $node;
	}

	public function is_classkindred_ready(ClassKindredDeclaration $node): bool
	{
		$key = spl_object_id($node);
		return isset(self::$preprocessed_classkindreds[$key]) && self::$preprocessed_classkindreds[$key] === $node;
	}

	public function mark_classkindred_ready(ClassKindredDeclaration $node): void
	{
		self::$preprocessed_classkindreds[spl_object_id($node)] = $node;
	}

	public function allows_none_coalescing_key_access(): bool
	{
		return $this->allow_none_coalescing_key_access;
	}

	public function set_none_coalescing_key_access(bool $allow): void
	{
		$this->allow_none_coalescing_key_access = $allow;
	}

	public function allows_php_soft_key_access(): bool
	{
		return $this->allow_php_soft_key_access;
	}

	public function set_php_soft_key_access(bool $allow): void
	{
		$this->allow_php_soft_key_access = $allow;
	}

	public function new_syntax_error(string $message, Node $node): Exception
	{
		if ($node->pos) {
			$program = $this->get_error_program_for_node($node);
			$place = $program->parser->get_error_place_with_pos($node->pos);
			$place = $this->format_error_place($program, $place);
		}
		else {
			$place = get_class($node);
			$node_name = $this->get_node_name_for_error($node);
			if ($node_name !== null) {
				$place .= " of '$node_name'";
			}

			$program = $this->get_error_program_for_node($node);
			$place = "Near $place on check {$program->name}";
			$unit = $program->unit;
			if ($unit !== null && $unit->name !== null) {
				$place = "{$unit->name}::{$place}";
			}
		}

		$message = "Syntax check error: {$message}\n-----\n{$place}\n-----";
		return new Exception($message);
	}

	public function add_syntax_warning(string $message, Node $node): void
	{
		$warning = $this->new_syntax_error($message, $node)->getMessage();
		$this->check_warnings[] = preg_replace('/^Syntax check error:/', 'Syntax check warning:', $warning);
	}

	public function consume_check_warnings(): array
	{
		$warnings = $this->check_warnings;
		$this->check_warnings = [];
		return $warnings;
	}

	private function get_error_program_for_node(Node $node): Program
	{
		if ($node instanceof BaseDeclaration) {
			if ($node->program !== null) {
				return $node->program;
			}

			$block = $node->belong_block;
			while ($block !== null) {
				if ($block instanceof BaseDeclaration && $block->program !== null) {
					return $block->program;
				}

				$block = $block instanceof IBlock ? $block->get_belong_block() : null;
			}
		}

		return $this->program;
	}

	private function format_error_place(Program $program, string $place): string
	{
		$unit = $program->unit;
		if ($unit === null) {
			return $place;
		}

		$unit_path = FileHelper::normalize_path($unit->path);
		$place = FileHelper::normalize_path($place);
		if (str_starts_with($place, $unit_path)) {
			$package_dir = basename(rtrim($unit_path, DS));
			$place = $package_dir . DS . substr($place, strlen($unit_path));
		}

		return $unit->name === null ? $place : "{$unit->name}::{$place}";
	}

	private function get_node_name_for_error(Node $node): ?string
	{
		if ($node instanceof BaseDeclaration || $node instanceof Identifiable) {
			return $node->name;
		}

		return null;
	}
}
