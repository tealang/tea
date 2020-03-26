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

	/**
	 * @var Program
	 */
	protected $program;

	// current token position
	protected $pos = -1;

	/**
	 * @var int[:]
	 */
	protected $line2pos = [];

	/**
	 * @var array
	 */
	protected $tokens;

	// the total tokens
	protected $tokens_count = 0;

	public function __construct(ASTFactory $factory, string $file)
	{
		$this->factory = $factory;
		$this->file = $file;

		$source = $this->read_file($file);
		$this->tokenize($source);

		$this->program = $this->factory->create_program($this->file, $this);

		// set to main program when file name is main.tea
		if ($this->program->name === _MAIN) {
			$this->factory->set_as_main_program();
		}
	}

	// public function get_program_ast()
	// {
	// 	return $this->program;
	// }

	abstract public function read_program(): Program;

	protected function read_file(string $file)
	{
		$source = file_get_contents($file);
		if ($source === false) {
			throw new \ErrorException("File '$file' to parse load failed.");
		}

		return $source;
	}

	abstract protected function tokenize(string $source);

	public function new_parse_error(string $message, int $trace_start = 0)
	{
		$place = $this->get_error_place_with_pos($this->pos);

		$message = "Syntax parse error:\n{$place}\n{$message}";
		DEBUG && $message .= "\n\nTraces:\n" . get_traces($trace_start);

		return new \Exception($message);
	}

	public function new_unexpected_error()
	{
		$token = $this->get_current_token_string();
		return $this->new_parse_error("Unexpected token '$token'", 1);
	}

	public function get_error_place_with_pos(int $pos)
	{
		$token = $this->get_current_token_string();
		if ($token === LF) {
			$pos--;
		}

		$line = $this->get_line_number($pos);
		$message = "{$this->file}:{$line}";

		$code = $this->get_previous_code_inline($pos);
		if (trim($code) !== '') {
			$code = self::tab2spaces($code);
			$pointer_spaces = str_repeat(' ', strlen($code) - 1);

			$after = $this->get_to_line_end($pos + 1);
			$code .= self::tab2spaces($after);

			$message .= "\n$code\n$pointer_spaces^";
		}

		return $message;
	}

	private static function tab2spaces(string $str)
	{
		return str_replace("\t", '    ', $str);
	}

	abstract protected function get_to_line_end(int $from = null);

	abstract protected function get_current_token_string();

	abstract protected function get_line_number(int $pos): int;

	abstract protected function get_previous_code_inline(int $pos): string;
}
