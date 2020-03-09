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
	public function render_unit_header_program(Program $header_program, array $normal_programs)
	{
		$this->process_use_statments($header_program);

		$this->program = $header_program;

		$unit = $header_program->unit;

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
		foreach ($normal_programs as $_program) {
			// render the Unit level functions & constants to __public.php
			foreach ($_program->declarations as $declaration) {
				if (!$declaration->is_unit_level) {
					continue;
				}

				if ($declaration instanceof ConstantDeclaration) {
					$item = $declaration->render($this);
					if ($item !== null) {
						$constants[] = $item;
					}
				}
				elseif ($declaration instanceof FunctionDeclaration && $declaration->body !== null) {
					$item = $declaration->render($this);
					$functions[] = $item . LF;
				}
			}
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
}
