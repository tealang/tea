<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class Unit
{
	public $docs;

	public $ns;

	// cache for render
	public $dist_ns_uri;

	// current unit path, include end with DS
	public $path;

	public $type; // tea or php

	public $loader; // the program to load PHP classes/functions/consts

	public $as_main_unit = false;

	/**
	 * @var array<Program>
	 */
	public $programs = [];

	/**
	 * @var array<string: Symbol>
	 */
	public $symbols = [];

	/**
	 * @var array<string: Unit>
	 */
	public $use_units = []; // units that used in programs

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

	public function count_subdirectory_levels_for_file(string $file_path)
	{
		$unit_dir_path = rtrim($this->path, DS);
		$count_path = dirname($file_path);

		$i = 0;
		while ($unit_dir_path !== $count_path) {
			$i++;
			$count_path = dirname($count_path);
		}

		return $i;
	}
}
