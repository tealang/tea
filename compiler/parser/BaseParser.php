<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
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
	public $pos = -1;

	protected $pos_before_skiped_comments = 0;

	/**
	 * @var UInt.Dict
	 */
	protected $line2pos = [];

	/**
	 * @var array
	 */
	protected $tokens;

	// the total tokens
	protected $tokens_count = 0;

	/**
	 * @var ASTFactory
	 */
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

	protected function read_body_for_control_block(IBlock $block)
	{
		$items = [];
		if ($this->skip_block_begin()) {
			while (($item = $this->read_inner_statement()) !== null) {
				$items[] = $item;
			}

			$this->expect_block_end();
		}
		else {
			$items[] = $this->read_inner_statement();
		}

		$block->set_body_with_statements($items);
		// $block->tailing_comment = $this->scan_line_comment_inline();
		// $block->tailing_newlines = $this->scan_empty_lines();

		$this->factory->end_block();
	}

	protected function read_body_for_decl(IBlock $block)
	{
		$this->expect_block_begin();

		$items = [];
		while (($item = $this->read_inner_statement()) !== null) {
			$items[] = $item;
		}

		$block->set_body_with_statements($items);

		$this->expect_block_end();
	}

	protected function read_prefix_operation(Operator $operator)
	{
		$expression = $this->read_expression($operator);
		if ($expression === null) {
			throw $this->new_unexpected_error();
		}

		$expression = $this->factory->create_prefix_operation($expression, $operator);
		$expression->pos = $this->pos;

		return $expression;
	}

	protected function create_normal_statement(?BaseExpression $expr = null)
	{
		$node = new NormalStatement($expr);
		$node->pos = $this->pos;
		return $node;
	}

	protected function create_line_comment(string $content)
	{
		$node = new LineComment($content);
		$node->pos = $this->pos;
		return $node;
	}

	protected function create_block_comment(string $content)
	{
		$node = new BlockComment($content);
		$node->pos = $this->pos;
		return $node;
	}

	protected function create_doc_comment(string $content)
	{
		$node = new DocComment($content);
		$node->pos = $this->pos;
		return $node;
	}

	protected function create_parameter(string $name)
	{
		$parameter = new ParameterDeclaration($name, null);
		$parameter->pos = $this->pos;
		return $parameter;
	}

	protected function back()
	{
		$this->pos--;
	}

	protected function back_skiped_comments()
	{
		$this->pos = $this->pos_before_skiped_comments;
	}

	public function new_parse_error(string $message, int $trace_start = 0)
	{
		$place = $this->get_error_place_with_pos($this->pos);

		$message = "Syntax parse error:\n{$place}\n{$message}";
		DEBUG && $message .= "\n\nTraces:\n" . get_traces($trace_start);

		return new Exception($message);
	}

	public function new_unexpected_error()
	{
		$this->print_token();
		$token_string = $this->get_current_token_string();
		return $this->new_parse_error("Unexpected token '$token_string'", 1);
	}

	protected function print_token(array|string $token = null)
	{
		$token === null and ($token = $this->tokens[$this->pos] ?? null);
		dump($token);
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
