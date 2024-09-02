<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class Unit implements IRootDeclaration
{
	public $name;

	public $ns;

	// cache for render
	public $dist_ns_uri;

	// current module path, include ends with DS
	public $path;

	public $loader; // the program to load PHP classes/functions/consts

	public $as_main = false;

	/**
	 * @var NamespaceDeclaration.Dict
	 */
	public $namespaces = [];

	/**
	 * @var Program[]
	 */
	public $programs = [];

	/**
	 * @var TopSymbol.Dict
	 */
	public $symbols = [];

	/**
	 * @var Unit.Dict
	 */
	public $use_units = []; // units that used in programs

	/**
	 * @var ASTFactory
	 */
	public $factory;

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
