<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class Unit
{
	public ?string $name = null;

	public ?NamespaceIdentifier $ns = null;

	// cache for render
	public ?string $dist_ns_uri = null;

	// current module path, include ends with DS
	public string $path;

	public ?Program $loader = null; // the program to load PHP classes/functions/consts

	public bool $as_main = false;

	// Builtin and current package declarations are checked from source, unlike foreign headers.
	public bool $is_trusted = false;

	/**
	 * @var NamespaceDeclaration[]
	 */
	public array $namespaces = [];

	/**
	 * @var Program[]
	 */
	public array $programs = [];

	/**
	 * @var array<string, Symbol>|null
	 */
	public ?array $symbols = null; // can be null during loading

	/**
	 * @var Unit[]
	 */
	public array $use_units = []; // units that used in programs

	/**
	 * @var array<string, bool>
	 */
	public array $trusted_use_units = [];

	public ?ASTFactory $factory = null;

	public function __construct(string $path)
	{
		$this->path = $path;
	}

	public function append_program(Program $program)
	{
		$this->programs[] = $program;
	}

	// just for paths belong to current Unit
	public function get_abs_path(string $path)
	{
		return substr($path, strlen($this->path));
	}
}

// end
