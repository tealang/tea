<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class BaseParser
{
	protected $file;

	protected $program;

	public function __construct(ASTFactory $factory, string $file)
	{
		$this->factory = $factory;
		$this->file = $file;

		$code = $this->load_file($file);
		$this->tokenize($code);

		$this->scan_program();
	}

	public function get_program_ast()
	{
		return $this->program;
	}

	protected function load_file(string $file)
	{
		$code = file_get_contents($file);
		if ($code === false) {
			throw new \ErrorException("File '$file' to parse load failed.");
		}

		return $code;
	}

	abstract protected function tokenize(string $code);

	abstract protected function scan_program();
}
