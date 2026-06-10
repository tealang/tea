<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class BaseParser
{
	public const SOURCE_DIALECT = Program::SOURCE_DIALECT_UNKNOWN;

	public bool $is_parsing_header = false;

	public bool $is_declare_mode = false;

	public bool $is_interface_mode = false;

	public ?int $origin_declare_mode = null;

	protected string $file;

	/**
	 * @var string
	 */
	protected string $source = '';

	/**
	 * @var Program
	 */
	protected Program $program;

	public int $pos = -1;

	protected int $pos_before_skiped_comments = 0;

	/**
	 * @var array<int, int>
	 */
	protected array $line2pos = [];

	/**
	 * @var array
	 */
	protected $tokens;

	protected int $tokens_count = 0;

	/**
	 * Collected errors during parsing
	 * @var array<array{message: string, place: string, pos: int, line: int, token: string}>
	 */
	protected array $parse_errors = [];

	/**
	 * Maximum errors to collect before stopping
	 */
	protected int $max_errors = 100;

	/**
	 * @var ASTFactory
	 */
	protected ASTFactory $factory;

	public function __construct(ASTFactory $factory, string $file, ?string $source = null)
	{
		$this->factory = $factory;
		$this->file = $file;

		$this->source = $source ?? $this->read_file($file);
		$this->tokenize($this->source);

		$this->program = $this->factory->create_program($this->file, $this);

		if ($this->program->name === _MAIN) {
			$this->factory->set_as_main();
		}
	}

	// create parser from source string, useful for unit testing
	public static function from_string(ASTFactory $factory, string $source, string $filename = 'memory'): static
	{
		return new static($factory, $filename, $source);
	}

	// Abstract methods that must be implemented by child classes
	// These are called in read_body_for_control_block() and read_body_for_decl()
	abstract protected function skip_block_begin();
	abstract protected function expect_block_begin();
	abstract protected function expect_block_end();
	abstract protected function read_inner_statement(): ?IStatement;
	abstract protected function read_expression(?Operator $prev_operator = null): BaseExpression;

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

	/**
	 * Create a parse error and collect it
	 * @return Exception The error exception (for throwing)
	 */
	public function new_parse_error(string $message, int $trace_start = 0): Exception
	{
		$place = $this->get_error_place_with_pos($this->pos);
		$token_string = $this->get_current_token_string();
		
		$error = [
			'message' => $message,
			'place' => $place,
			'pos' => $this->pos,
			'line' => $this->get_line_number($this->pos),
			'token' => $token_string,
		];
		
		// Collect error
		$this->parse_errors[] = $error;
		
		// Create exception for potential throwing
		$full_message = "Syntax parse error:\n{$place}\n{$message}";
		
		// Stop if too many errors
		if (count($this->parse_errors) >= $this->max_errors) {
			$this->throw_all_errors();
		}
		
		return new Exception($full_message);
	}

	/**
	 * Create an unexpected token error
	 * @return Exception The error exception (for throwing)
	 */
	public function new_unexpected_error(): Exception
	{
		$token_string = $this->get_current_token_string();
		return $this->new_parse_error("Unexpected token '$token_string'");
	}

	/**
	 * Throw all collected errors as a single exception
	 * @throws Exception
	 */
	public function throw_all_errors(): void
	{
		if (empty($this->parse_errors)) {
			return;
		}

		$error_count = count($this->parse_errors);
		$message = "Syntax parse errors ({$error_count} found):\n\n";
		
		foreach ($this->parse_errors as $i => $error) {
			$message .= ($i + 1) . ". {$error['place']}\n";
			$message .= "   {$error['message']}\n";
			if ($i < $error_count - 1) {
				$message .= "\n";
			}
		}

		$this->parse_errors = [];
		throw new Exception($message);
	}

	/**
	 * Get collected errors without throwing
	 * @return array<array{message: string, pos: int, line: int, token: string}>
	 */
	public function get_parse_errors(): array
	{
		return $this->parse_errors;
	}

	/**
	 * Clear collected errors
	 */
	public function clear_parse_errors(): void
	{
		$this->parse_errors = [];
	}

	/**
	 * Check if there are collected errors
	 */
	public function has_parse_errors(): bool
	{
		return !empty($this->parse_errors);
	}

	protected function print_token(array|string|null $token = null)
	{
		$token === null and ($token = $this->tokens[$this->pos] ?? null);
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
		if ($code !== '') {
			$last_br_pos = strrpos($code, "\n");
			if ($last_br_pos !== false) {
				$code = substr($code, $last_br_pos + 1);
			}

			$code = self::tab2spaces($code);
			$pointer_pre_len = strlen($code) - 1;
			$pointer_spaces = str_repeat(' ', $pointer_pre_len < 0 ? 0 : $pointer_pre_len);

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

	protected abstract function get_to_line_end(?int $from = null): string;

	protected abstract function get_current_token_string(): array|string|null;

	protected abstract function get_line_number(int $pos): int;

	protected abstract function get_previous_code_inline(int $pos): string;
}

// end
