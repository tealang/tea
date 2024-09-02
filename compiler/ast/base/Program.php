<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class Program extends Node
{
	use DeclarationTrait;

	const KIND = 'program';

	/**
	 * @var BaseParser
	 */
	public $parser;

	public $as_main;

	public $name;

	public $file;

	/**
	 * using statements that in current program
	 * @var UseStatement[]
	 */
	public $uses = [];

	/**
	 * targets of use statements in current program
	 * @var UseDeclaration[]
	 */
	public $use_targets = [];

	public $declarations = [];

	/**
	 * @var FunctionDeclaration
	 */
	public $initializer;

	public $symbols = [];

	public $unit;

	/**
	 * for PHP scripts
	 * @var NamespaceIdentifier
	 */
	public $ns;

	public $depends_native_programs = [];

	public $is_native = false; // for native programs, e.g. PHP scripts

	public $is_external = false;

	public function __construct(string $file, Unit $unit)
	{
		$this->unit = $unit;
		$this->file = $file;
		$this->name = $this->generate_name();
	}

	public function append_declaration(IDeclaration $declaration)
	{
		$this->declarations[(string)$declaration->name] = $declaration;
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
