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

	public $checked; // set true when checked by ASTChecker

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

	public function append_defer_check_for_block(BaseBlock $block)
	{
		if (!$block->defer_check_identifiers) {
			return;
		}

		$this->defer_check_identifiers = array_merge($this->defer_check_identifiers, $block->defer_check_identifiers);
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
