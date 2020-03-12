<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class PHPPublicCoder extends PHPCoder
{
	private $constants = [];
	private $functions = [];

	public function render_public_program(Program $header_program, array $normal_programs)
	{
		$this->program = $header_program;

		$unit = $header_program->unit;

		$this->collect_declarations($normal_programs);

		$this->process_use_statments($this->constants);
		$this->process_use_statments($this->functions);

		$items = $this->render_heading_statements($header_program);

		// the builtin constants
		$items[] = 'const ' . _UNIT_PATH . ' = __DIR__ . DIRECTORY_SEPARATOR;';

		// put an empty line
		$items[] = '';

		// load the dependence units
		if ($unit->use_units || $unit->as_main_unit) {
			// 由于PHP不支持在const中使用函数表达式，故使用变量替代
			// 另外，主Unit的super_path和被引用库的可能是不一致的
			$items[] = "\$super_path = dirname(__DIR__, {$unit->super_dir_levels}) . DIRECTORY_SEPARATOR; // the workspace/vendor path";

			// load the builtins
			if ($unit->as_main_unit) {
				$items[] = sprintf("require_once \$super_path . '%s'; // the builtins", Compiler::BUILTIN_LOADING_FILE);
			}

			// load the foriegn units
			foreach ($unit->use_units as $foreign_unit) {
				if ($foreign_unit->required_loading) {
					$items[] = "require_once \$super_path . '{$foreign_unit->loading_file}';";
				}
			}

			$items[] = '';
		}

		// render constants and function defined in current Unit
		// because of them can not be autoloaded like classes
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
		$autoloads = self::stringfy_autoloads($autoloads);

		return "
// autoloads
const __AUTOLOADS = {$autoloads};

spl_autoload_register(function (\$class) {
	isset(__AUTOLOADS[\$class]) && require UNIT_PATH . __AUTOLOADS[\$class];
});

// end
";
	}

	private static function stringfy_autoloads(array $autoloads)
	{
		$items = [];
		foreach ($autoloads as $class => $file) {
			$items[] = "'$class' => '$file'";
		}

		$items = join(",\n\t", $items);

		return "[\n\t$items\n]";
	}
}
