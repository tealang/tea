<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class PHPLoaderCoder extends PHPCoder
{
	private $constants = [];
	private $functions = [];

	public function render_loader_program(Program $header_program, array $normal_programs, array $loaders)
	{
		$this->program = $header_program;

		$this->collect_declarations($normal_programs);

		$this->process_use_statments($this->constants);
		$this->process_use_statments($this->functions);

		$items = $this->render_base_statements($header_program, $loaders);

		// render constants and function defined in current Unit
		// because of them can not be autoloaded like Classes
		$constants = [];
		$functions = [];

		foreach ($this->constants as $node) {
			$item = $node->render($this);
			if ($item !== null) {
				$constants[] = $item;
			}
		}

		foreach ($this->functions as $node) {
			$item = $node->render($this);
			$functions[] = $item . LF;
		}

		// put constants at the front
		if ($constants) {
			$items = array_merge($items, $constants);
			$items[] = '';
		}

		// put functions before the constants
		if ($functions) {
			$items = array_merge($items, $functions);
			$items[] = '';
		}

		return $this->join_code($items);
	}

	protected function render_base_statements(Program $program, array $loaders)
	{
		$items = parent::render_heading_statements($program);

		// the builtin constants
		$items[] = 'const ' . _UNIT_PATH . ' = __DIR__ . DIRECTORY_SEPARATOR;';

		// put an empty line
		$items[] = '';

		// load the dependence units
		$unit = $program->unit;
		if ($loaders || $unit->as_main) {
			// workspace path
			$based_level = count($unit->ns->names);
			if ($based_level > 1) {
				$based_level--;
			}

			if (self::check_used_path_type(Compiler::BASED_FAMILLY, $loaders)) {
				$items[] = "\$work_path = dirname(__DIR__, {$based_level}) . DIRECTORY_SEPARATOR;";
			}

			// super path
			if ($unit->as_main or self::check_used_path_type(Compiler::BASED_WORKSPACE, $loaders)) {
				$based_level += 1;
				$items[] = "\$super_path = dirname(__DIR__, {$based_level}) . DIRECTORY_SEPARATOR;";
			}

			// load the builtins
			if ($unit->as_main) {
				$items[] = sprintf("require_once \$super_path . 'tea-modules/%s';", BUILTIN_LOADER_FILE);
			}

			// load the foriegn units
			foreach ($loaders as $loader) {
				$based_var_name = $loader[0] === Compiler::BASED_FAMILLY
					? '$work_path'
					: '$super_path';

				$items[] = "require_once {$based_var_name} . '{$loader[1]}';";
			}

			$items[] = '';
		}

		return $items;
	}

	private static function check_used_path_type(int $type, array $loaders)
	{
		foreach ($loaders as $loader) {
			if ($loader[0] === $type) {
				return true;
			}
		}

		return false;
	}

	private function collect_declarations(array $programs)
	{
		foreach ($programs as $program) {
			foreach ($program->declarations as $node) {
				if (!$node->is_unit_level) {
					continue;
				}

				if ($node instanceof ConstantDeclaration) {
					$this->constants[] = $node;
				}
				elseif ($node instanceof FunctionDeclaration && $node->body !== null) {
					$this->functions[] = $node;
				}
			}
		}
	}

	public function render_autoloads_code(array $autoloads)
	{
		$autoloads = self::stringify_autoloads($autoloads);

		$include_stmts = $this->render_internal_includes($this->program->unit);
		$include_stmts = join("\n", $include_stmts);

		return "
// autoloads
const __AUTOLOADS = {$autoloads};

spl_autoload_register(function (\$class) {
	isset(__AUTOLOADS[\$class]) && require UNIT_PATH . __AUTOLOADS[\$class];
});

{$include_stmts}

// end
";
	}


	private function render_internal_includes(Unit $unit)
	{
		$items = [];
		// programs that's has constants/functions
		foreach ($unit->programs as $program) {
			if ($program->name !== '__package' && $this->is_need_include_at_loader($program)) {
				$items[] = "require_once UNIT_PATH . '{$program->name}.php';";
			}
		}

		return $items;
	}

	private function is_need_include_at_loader(Program $program)
	{
		$is = false;
		foreach ($program->declarations as $node) {
			$is = $node->is_unit_level
				&& ($node instanceof ConstantDeclaration
					|| $node instanceof FunctionDeclaration);
			if ($is) {
				break;
			}
		}

		return $is;
	}

	private static function stringify_autoloads(array $autoloads)
	{
		$items = [];
		foreach ($autoloads as $class => $file) {
			$items[] = "'$class' => '$file'";
		}

		$items = join(",\n\t", $items);

		return "[\n\t$items\n]";
	}
}
