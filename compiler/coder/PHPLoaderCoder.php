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
		if ($loaders || $unit->as_main_unit) {
			// workspace path
			$dir_levels = count($unit->ns->names);
			if ($dir_levels > 1) {
				$dir_levels -= 1;
			}

			if (self::check_used_path_type(Compiler::BASE_WORKSPACE, $loaders)) {
				$items[] = "\$work_path = dirname(__DIR__, {$dir_levels}) . DIRECTORY_SEPARATOR;";
			}

			// super path
			if ($unit->as_main_unit or self::check_used_path_type(Compiler::BASE_SUPER, $loaders)) {
				$dir_levels += 1;
				$items[] = "\$super_path = dirname(__DIR__, {$dir_levels}) . DIRECTORY_SEPARATOR;";
			}

			// load the builtins
			if ($unit->as_main_unit) {
				$items[] = sprintf("require_once \$super_path . 'tea-modules/%s';", BUILTIN_LOADER_FILE);
			}

			// load the foriegn units
			foreach ($loaders as $loader) {
				$based_var_name = $loader[0] === Compiler::BASE_WORKSPACE
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

	public static function render_autoloads_code(array $autoloads)
	{
		$autoloads = self::stringify_autoloads($autoloads);

		return "
// autoloads
const __AUTOLOADS = {$autoloads};

spl_autoload_register(function (\$class) {
	isset(__AUTOLOADS[\$class]) && require UNIT_PATH . __AUTOLOADS[\$class];
});

// end
";
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
