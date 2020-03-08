<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class Program extends Node
{
	use DeferChecksTrait;

	const KIND = 'program';

	public $as_main_program = false;

	public $docs;

	public $name;

	public $file;

	/**
	 * the use statements in current program
	 * @var UseStatement[]
	 */
	public $uses = [];

	/**
	 * the targets of use statements in current program
	 * @var UseDeclcaration[]
	 */
	public $use_targets = [];

	public $declarations = [];

	public $main_function;

	public $symbols = [];

	public $unit;

	/**
	 * just used for PHP scripts
	 * @var NamespaceIdentifier
	 */
	public $ns;

	public $depends_native_programs = [];

	public $is_native = false; // for native programs, eg. PHP scripts

	public $is_checked = false; // set true when has been checked by ASTChecker

	private $subdirectory_levels;

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

	public function append_defer_check_identifiers(IDeclaration $declaration)
	{
		if (!$declaration->defer_check_identifiers) {
			return;
		}

		$this->defer_check_identifiers = array_merge($this->defer_check_identifiers, $declaration->defer_check_identifiers);
	}

	public function append_depends_native_program(Program $program)
	{
		$this->depends_native_programs[$program->name] = $program;
	}

	public function get_subdirectory_levels()
	{
		$unit_dir_path = rtrim($this->unit->path, DS);
		$count_path = dirname($this->file);

		$i = 0;
		while ($unit_dir_path !== $count_path) {
			$i++;
			$count_path = dirname($count_path);
		}

		if ($this->unit->is_mixed_mode) {
			$i++;
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
