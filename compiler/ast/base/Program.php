<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class Program extends BaseDeclaration
{
	const KIND = 'program';

	const SOURCE_DIALECT_UNKNOWN = 'unknown';
	const SOURCE_DIALECT_TEA = 'tea';
	const SOURCE_DIALECT_PHP = 'php';
	const SOURCE_DIALECT_HEADER = 'header';

	/**
	 * @var BaseParser
	 */
	public ?BaseParser $parser = null;

	public string $source_dialect = self::SOURCE_DIALECT_UNKNOWN;

	public bool $as_main = false;

	public ?string $file = null;

	/**
	 * using statements that in current program
	 * @var UseStatement[]
	 */
	public array $uses = [];

	/**
	 * targets of use statements in current program
	 * @var UseDeclaration[]
	 */
	public array $use_targets = [];

	/**
	 * @var array<int, BaseDeclaration>
	 */
	public array $declarations = [];

	/**
	 * @var FunctionDeclaration|null
	 */
	public ?FunctionDeclaration $initializer = null;

	/**
	 * @var array<string, mixed>
	 */
	public array $symbols = [];

	public ?Unit $unit = null;

	/**
	 * for PHP scripts
	 */
	public ?NamespaceIdentifier $ns = null;

	public array $depends_native_programs = [];

	public bool $is_native = false; // for native programs, e.g. PHP scripts

	public bool $is_external = false;

	public function __construct(string $file, Unit $unit)
	{
		$this->unit = $unit;
		$this->file = $file;
		$this->name = $this->generate_name();
	}

	public function append_declaration(BaseDeclaration $decl)
	{
		$this->declarations[(string)$decl->name] = $decl;
	}

	public function append_depends_native_program(Program $program)
	{
		$this->depends_native_programs[$program->name] = $program;
	}

	public function count_subdirectory_levels()
	{
		$unit_path = rtrim($this->unit->path, DS);

		$i = 0;
		$count_path = dirname($this->file);
		while ($unit_path !== $count_path) {
			$i++;
			$count_path = dirname($count_path);
		}

		return $i;
	}

	private function generate_name()
	{
		$name = $this->unit->get_abs_path($this->file);

		if ($dot_pos = strrpos($name, _DOT)) {
			$name = substr($name, 0, $dot_pos);
		}

		return $name;
	}
}

// end
