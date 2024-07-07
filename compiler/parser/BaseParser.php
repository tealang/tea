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
	public $is_parsing_header = false;

	public $is_declare_mode = false;

	public $is_interface_mode = false;

	public $origin_declare_mode;

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

	protected $factory;

	public function __construct(ASTFactory $factory, string $file)
	{
		$this->factory = $factory;
		$this->file = $file;

		$source = $this->read_file($file);
		$this->tokenize($source);

		$this->program = $this->factory->create_program($this->file, $this);

		// set to main program when file name is main.tea
		if ($this->program->name === _MAIN) {
			$this->factory->set_as_main();
		}
	}

	protected function set_declare_mode(bool $mode)
	{
		$this->origin_declare_mode = $this->is_declare_mode;
		$this->is_declare_mode = $mode;
	}

	protected function fallback_declare_mode()
	{
		$this->is_declare_mode = $this->origin_declare_mode;
	}

	public abstract function read_program(): Program;

	protected function read_file(string $file)
	{
		$source = file_get_contents($file);
		if ($source === false) {
			throw new ErrorException("File '$file' to parse load failed.");
		}

		return $source;
	}

	protected abstract function tokenize(string $source);

	public function new_parse_error(string $message, int $trace_start = 0)
	{
		$place = $this->get_error_place_with_pos($this->pos);

		$message = "Syntax parse error:\n{$place}\n{$message}";
		DEBUG && $message .= "\n\nTraces:\n" . get_traces($trace_start);

		return new Exception($message);
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

	public function attach_position(Node $node)
	{
		$node->pos = $this->pos;
	}

	private static function tab2spaces(string $str)
	{
		return str_replace("\t", '    ', $str);
	}

	protected abstract function get_to_line_end(int $from = null);

	protected abstract function get_current_token_string();

	protected abstract function get_line_number(int $pos): int;

	protected abstract function get_previous_code_inline(int $pos): string;
}

// end
