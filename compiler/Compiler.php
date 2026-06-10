<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

const BUILTIN_PATH = TEA_BASE_PATH . 'packages/builtin/';
const BUILTIN_CORE_PROGRAM = BUILTIN_PATH . 'src/core.tea';

class Compiler
{
	const BASED_FAMILLY = 0;
	const BASED_WORKSPACE = 1;

	// the path of current compiling unit
	private $unit_path;

	// the built results path
	private $unit_dist_path;

	// the prefix length use to trim path prefix
	private $unit_path_prefix_len;

	// use to generate the autoloads classmap
	private $unit_dist_ns_prefix;

	private $unit_public_file;

	private $unit_loader_file;

	// the workspace path to load units
	private $work_path;

	private $super_path;

	// for render code
	private $loaders = [];

	// header file path of current unit
	private $header_file_path;

	private $search_dirs = ['', 'units', 'vendor'];

	// autoload classes/interfaces/traits for render Autoload
	private $autoloads_map = [];

	// runtime properties
	private Unit $unit;
	private ASTFactory $ast_factory;
	private ?Unit $builtin_unit = null;
	private Program $header_program;
	private Program $program;

	/**
	 * Collected parse errors from all files
	 * @var array<array{message: string, place: string, pos: int, line: int, token: string}>
	 */
	private array $collected_parse_errors = [];

	/**
	 * Collected check errors from all programs
	 * @var array<array{unit: string, stage: string, place: string, message: string, trace: string[]}>
	 */
	private array $collected_check_errors = [];

	private array $collected_check_warnings = [];

	/**
	 * @var string[]
	 */
	private array $native_program_files = [];

	/**
	 * @var Program[]
	 */
	private array $native_programs = [];

	/**
	 * @var string[]
	 */
	private array $normal_program_files = [];

	/**
	 * @var Program[]
	 */
	private array $normal_programs = [];

	/**
	 * @var array<string, Unit>
	 */
	private array $units_pool = [];

	private bool $is_src_dir = true;

	public function __construct()
	{
		//
	}

	private function init_unit(string $unit_path)
	{
		$unit_path = FileHelper::normalize_path($unit_path . DS);

		ASTFactory::init_ast_system();

		$this->header_file_path = $unit_path . UNIT_HEADER_FILE_NAME;
		if (!file_exists($this->header_file_path)) {
			$this->end_error("The header file '{$this->header_file_path}' not found");
		}

		// init unit
		$this->unit = new Unit($unit_path);
		$this->ast_factory = new ASTFactory($this->unit);

		$this->unit_path = $unit_path;

		if (!self::is_builtin_package($unit_path)) {
			$this->load_builtin_package();
		}

		return $unit_path;
	}

	private static function is_builtin_package(string $unit_path)
	{
		return strtolower(BUILTIN_PATH) === strtolower($unit_path);
	}

	private function load_builtin_package(string $unit_path = BUILTIN_PATH)
	{
		$this->builtin_unit = new Unit($unit_path);
		$this->builtin_unit->is_trusted = true;

		$ast_factory = new ASTFactory($this->builtin_unit);

		$public_header_file = $unit_path . PUBLIC_HEADER_FILE_NAME;
		if (file_exists($public_header_file)) {
			$program = $this->parse_tea_header($public_header_file, $ast_factory);
			// lets render namespace as root
			$program->unit = null;
			return;
		}

		// fallback for legacy builtin package without __public.th
		$unit_header_file = $unit_path . UNIT_HEADER_FILE_NAME;
		$program = $this->parse_tea_header($unit_header_file, $ast_factory);
		$program->unit = null;

		$program = $this->parse_tea_program(BUILTIN_CORE_PROGRAM, $ast_factory);
		$program->unit = null;
	}

	public function make(string $unit_path)
	{
		$unit_path = $this->init_unit($unit_path);

		// for generate the autoload maps
		$this->unit_path_prefix_len = strlen($unit_path);
		$this->prepare_program_file_lists($unit_path);

		$this->parse_unit_header();
		$this->parse_programs();

		// bind the builtin type symbols
		TypeFactory::set_symbols($this->builtin_unit ?? $this->unit);

		// load deps for compiling unit
		$this->load_dependences_for_unit($this->unit);

		$this->check_ast();

		$this->render_all();

		return count($this->normal_programs);
	}

	public function parse_only(string $unit_path): int
	{
		$unit_path = $this->init_unit($unit_path);
		$this->unit_path_prefix_len = strlen($unit_path);
		$this->prepare_program_file_lists($unit_path);

		$this->parse_unit_header();
		$this->parse_programs();

		return count($this->native_programs) + count($this->normal_programs);
	}

	private function prepare_program_file_lists(string $unit_path): void
	{
		// Scan files based on directory structure
		$src_path = $unit_path . SRC_DIR_NAME . DS;
		if (is_dir($src_path)) {
			// If src/ directory exists:
			// - Scan src/ for Tea files (.tea)
			// - Scan src/ for PHP files (.php)
			// - Also scan php/ directory if exists
			$this->normal_program_files = $this->scan_program_files($src_path, TEA_EXT_NAME);
			$this->native_program_files = $this->scan_program_files($src_path, PHP_EXT_NAME);

			// Also scan php/ directory if exists (for backward compatibility)
			$php_path = $unit_path . 'php' . DS;
			if (is_dir($php_path)) {
				$php_files = $this->scan_program_files($php_path, PHP_EXT_NAME);
				$this->native_program_files = array_merge($this->native_program_files ?? [], $php_files);
			}
		}
		else {
			// If no src/ directory, scan the entire package directory
			$this->normal_program_files = $this->scan_program_files($unit_path, TEA_EXT_NAME);
			$this->native_program_files = $this->scan_program_files($unit_path, PHP_EXT_NAME);
		}
	}

	private function parse_programs()
	{
		self::echo_start('Parsing programs...', LF);

		$unit_uri = $this->unit->ns->uri;

		// parse native programs
		if ($this->native_program_files) {
			foreach ($this->native_program_files as $file) {
				try {
					$program = $this->parse_php_program($file);
					if (!$program->ns or $program->ns->uri !== $unit_uri) {
						$program->is_external = true;
						// continue;
					}

					$this->native_programs[] = $program;
				} catch (Exception $e) {
					// Collect error message and continue parsing other files
					$this->collected_parse_errors[] = [
						'message' => $e->getMessage(),
						'place' => str_replace($this->unit->path, '', $file),
						'pos' => 0,
						'line' => 0,
						'token' => '',
					];
				}
			}

			self::echo_success(count($this->native_programs) . ' PHP programs parsed.');
		}

		// parse tea programs
		foreach ($this->normal_program_files as $file) {
			try {
				$this->normal_programs[] = $this->parse_tea_program($file, $this->ast_factory);
			} catch (Exception $e) {
				// Collect error message and continue parsing other files
				$this->collected_parse_errors[] = [
					'message' => $e->getMessage(),
					'place' => str_replace($this->unit->path, '', $file),
					'pos' => 0,
					'line' => 0,
					'token' => '',
				];
			}
		}

		self::echo_success(count($this->normal_programs) . " Tea programs parsed.\n");
		
		// Throw all collected parse errors after parsing all files
		$this->throw_collected_parse_errors();
	}

	private function throw_collected_parse_errors(): void
	{
		if (empty($this->collected_parse_errors)) {
			return;
		}

		$error_count = count($this->collected_parse_errors);
		$message = "Syntax parse errors ({$error_count} found):\n\n";
		
		foreach ($this->collected_parse_errors as $i => $error) {
			$message .= ($i + 1) . ". {$error['place']}\n";
			$message .= "   {$error['message']}\n";
			if ($i < $error_count - 1) {
				$message .= "\n";
			}
		}

		$this->collected_parse_errors = [];
		throw new Exception($message);
	}

	private function parse_unit_header()
	{
		// parse header file
		$this->header_program = $this->parse_tea_header($this->header_file_path, $this->ast_factory);

		// check the package is defined
		if (!$this->unit->ns) {
			$this->end_error("'namespace' declaration not found in the header file: {$this->header_file_path}");
		}

		$this->prepare_unit_paths();
	}

	private function prepare_unit_paths()
	{
		$based_level = count($this->unit->ns->names);
		if ($based_level > 1) {
			$based_level--;
		}

		$this->work_path = dirname($this->unit_path, $based_level) . DS;
		$this->super_path = dirname($this->work_path) . DS;

		// set the dist paths
		$this->unit_dist_path = $this->unit_path . DIST_DIR_NAME . DS;
		$this->unit_public_file = $this->unit_path . PUBLIC_HEADER_FILE_NAME;
		$this->unit_loader_file = $this->unit_path . PUBLIC_LOADER_FILE_NAME;
	}

	private function check_ast()
	{
		self::echo_start('Initing...', LF);
		SemanticContext::reset_check_state();

		$builtin_unit = $this->builtin_unit;

		// if current it is not the builtin unit, the builtin unit needs to be checked first
		if ($builtin_unit) {
			ASTChecker::init_checkers($builtin_unit);
			$program = $builtin_unit->programs[PUBLIC_HEADER_NAME] ?? $builtin_unit->programs['__package'];
			$normal_checker = ASTChecker::get_checker($program);
			$this->check_ast_for_unit($builtin_unit, $normal_checker);
		}

		ASTChecker::init_checkers($this->unit, $builtin_unit);

		// check depends
		foreach ($this->collect_dependency_units($this->unit) as $dep_unit) {
			$program = $dep_unit->programs[PUBLIC_HEADER_NAME];
			$normal_checker = ASTChecker::get_checker($program);
			$this->check_ast_for_unit($dep_unit, $normal_checker);
		}

		// check current unit
		$normal_checker = ASTChecker::get_checker($this->header_program);
		$this->check_ast_for_unit($this->unit, $normal_checker);

		$this->throw_collected_check_errors();
		$this->print_collected_check_warnings();

		self::echo_success('Programs checked.' . LF);
	}

	/**
	 * @return Unit[]
	 */
	private function collect_dependency_units(Unit $unit): array
	{
		$seen = [$unit->path => true];
		$dependencies = [];
		$this->collect_dependency_units_for($unit, $seen, $dependencies);
		return $dependencies;
	}

	/**
	 * @param array<string, bool> $seen
	 * @param Unit[] $dependencies
	 */
	private function collect_dependency_units_for(Unit $unit, array &$seen, array &$dependencies): void
	{
		foreach ($unit->use_units as $dep_unit) {
			if (!$dep_unit instanceof Unit) {
				continue;
			}

			if (isset($seen[$dep_unit->path])) {
				continue;
			}

			$seen[$dep_unit->path] = true;
			$this->collect_dependency_units_for($dep_unit, $seen, $dependencies);
			$dependencies[] = $dep_unit;
		}
	}

	private function check_ast_for_unit(Unit $unit, ASTChecker $normal_checker)
	{
		self::echo_start("\nStart checking: {$unit->name}", "\n");

		$native_checker = ASTChecker::get_native_checker();
		$programs = $unit->programs;
		$blocked_programs = [];

		// 1st, process all namespace usings
		self::echo_start("  Process namespace usings...", "\n");
		foreach ($programs as $program) {
			try {
				if ($program->is_native) {
					$native_checker->check_all_usings($program);
				}
				else {
					$normal_checker->check_all_usings($program);
				}
			}
			catch (Exception $e) {
				$blocked_programs[spl_object_id($program)] = true;
				$this->collect_check_error($unit, $program, 'check_usings', $e);
			}
		}

		// 2nd, link symbols, and collecting auto namespace usings
		self::echo_start("  Link symbols...", "\n");
		foreach ($programs as $program) {
			if (isset($blocked_programs[spl_object_id($program)])) {
				continue;
			}

			try {
				// self::echo_start(" - {$program->file}", "\n");
				$checker = $program->is_native ? $native_checker : $normal_checker;
				$checker->link_declarations($program);
			}
			catch (Exception $e) {
				$blocked_programs[spl_object_id($program)] = true;
				$this->collect_check_error($unit, $program, 'link', $e);
			}
		}

		// 3rd, preprocess declarations to build inheritance / trait aggregation before semantic checks
		self::echo_start("  Preprocess declarations...", "\n");
		foreach ($programs as $program) {
			if (isset($blocked_programs[spl_object_id($program)])) {
				continue;
			}

			try {
				$checker = $program->is_native ? $native_checker : $normal_checker;
				$checker->preprocess_declarations($program);
			}
			catch (Exception $e) {
				$blocked_programs[spl_object_id($program)] = true;
				$this->collect_check_error($unit, $program, 'preprocess', $e);
			}
		}

		// 4th, check details
		self::echo_start("  Check details...", "\n");
		$package_program = $programs['__package'] ?? null;
		if ($package_program && !isset($blocked_programs[spl_object_id($package_program)])) {
			try {
				$normal_checker->check_program($package_program);
				$this->collect_check_warnings($unit, $package_program, 'check_package', $normal_checker);
			}
			catch (Exception $e) {
				$this->collect_check_error($unit, $package_program, 'check_package', $e);
			}
		}

		foreach ($programs as $program) {
			if (!$program->is_native || isset($blocked_programs[spl_object_id($program)])) {
				continue;
			}

			try {
				$native_checker->check_program($program);
				$this->collect_check_warnings($unit, $program, 'check_native', $native_checker);
			}
			catch (Exception $e) {
				$this->collect_check_error($unit, $program, 'check_native', $e);
			}
		}

		foreach ($programs as $program) {
			if ($program->is_native || isset($blocked_programs[spl_object_id($program)])) {
				continue;
			}

			try {
				$normal_checker->check_program($program);
				$this->collect_check_warnings($unit, $program, 'check_normal', $normal_checker);
			}
			catch (Exception $e) {
				$this->collect_check_error($unit, $program, 'check_normal', $e);
			}
		}
	}

	private function collect_check_error(Unit $unit, Program $program, string $stage, Exception $e): void
	{
		$this->collected_check_errors[] = CompilerErrorReporter::create_exception_issue($unit, $program, $stage, $e);
	}

	private function collect_check_warnings(Unit $unit, Program $program, string $stage, ASTChecker $checker): void
	{
		$warnings = $checker->consume_check_warnings();
		foreach (CompilerErrorReporter::create_warning_issues($unit, $program, $stage, $warnings) as $warning) {
			$this->collected_check_warnings[] = $warning;
		}
	}

	private function throw_collected_check_errors(): void
	{
		if (empty($this->collected_check_errors)) {
			return;
		}

		$message = CompilerErrorReporter::format_check_issues($this->collected_check_errors, 'Syntax check failed.');
		$this->collected_check_errors = [];
		throw new Exception($message);
	}

	private function print_collected_check_warnings(): void
	{
		if (empty($this->collected_check_warnings)) {
			return;
		}

		$warnings = CompilerErrorReporter::format_check_issues($this->collected_check_warnings, 'Syntax check warnings.');
		$this->collected_check_warnings = [];
		echo LF . $warnings . LF;
	}

	private function load_dependences_for_unit(Unit $unit)
	{
		$current_unit_uri = $this->unit->ns->uri;

		foreach ($unit->use_units as $uri => $target) {
			$is_trusted = $unit->trusted_use_units[$uri] ?? false;
			if ($target instanceof Unit) {
				if ($is_trusted) {
					$target->is_trusted = true;
				}
			}
			elseif ($target->uri !== '' and $target->uri !== $current_unit_uri) {
				$use_unit = $this->load_unit_for_namespace($target);
				if ($is_trusted) {
					$use_unit->is_trusted = true;
				}
				$unit->use_units[$uri] = $use_unit;
			}
			else {
				unset($unit->use_units[$uri]);
			}
		}
	}

	private function load_unit_for_namespace(NamespaceIdentifier $ns): Unit
	{
		$uri = $ns->uri;

		// if has a cache
		if (isset($this->units_pool[$uri])) {
			return $this->units_pool[$uri];
		}

		// find the target relative path
		[$path_based_type, $unit_dir] = $this->find_unit_dir_for_namespace($ns);

		$unit_path = $path_based_type === self::BASED_WORKSPACE
			? $this->super_path
			: $this->work_path;

		if ($unit_dir !== null) {
			$unit_path .= $unit_dir . DS;
		}

		// check public file
		$unit_public_file = $unit_path . PUBLIC_HEADER_FILE_NAME;
		// if (!file_exists($unit_public_file)) {
		// 	$this->end_error("The public file of module '{$ns->uri}' not found at: $unit_public_file");
		// }

		$unit = new Unit($unit_path);

		$loader_file = $unit_dir . DS . PUBLIC_LOADER_FILE_NAME; // for render the require statements
		$this->loaders[$uri] = [$path_based_type, $loader_file];

		// add to pool, so do not need to reload
		$this->units_pool[$uri] = $unit;

		// parse its public header
		$ast_factory = new ASTFactory($unit);
		$this->parse_tea_header($unit_public_file, $ast_factory);

		// load deps for it
		$this->load_dependences_for_unit($unit);

		return $unit;
	}

	private function find_unit_dir_for_namespace(NamespaceIdentifier $ns)
	{
		$relative_path = $this->try_search_all_dirs($ns->names, $this->work_path);

		// second search
		if ($relative_path === false) {
			$relative_path = $this->try_search_all_dirs($ns->names, $this->super_path);
			$path_based_type = self::BASED_WORKSPACE;
		}
		else {
			$path_based_type = self::BASED_FAMILLY;
		}

		if ($relative_path === false) {
			$dirs = $this->work_path . join("', '{$this->work_path}", $this->search_dirs);
			$this->end_error("The depends module '{$ns->uri}' not found in ('{$dirs}')");
		}

		return [$path_based_type, $relative_path];
	}

	private function try_search_all_dirs(array $names, string $searching_path)
	{
		if ($names[0] === '') {
			array_shift($names);
		}

		$relative_path = null;
		foreach ($this->search_dirs as $dir) {
			$relative_path = $this->try_look_in_dir($dir, $names, $searching_path);
			if ($relative_path) {
				break;
			}
		}

		$unit_path = $searching_path;
		if ($relative_path !== null) {
			$unit_path .= $relative_path . DS;
		}

		// check public file
		$unit_public_file = $unit_path . PUBLIC_HEADER_FILE_NAME;
		if (!file_exists($unit_public_file)) {
			return false;
		}

		return $relative_path;
	}

	private function try_look_in_dir(string $dir, array $namespace_components, string $searching_path)
	{
		$dir_components = [];

		$path = $searching_path;
		if ($dir !== '') {
			$path .= $dir . DS;
			$dir_components[] = $dir;
		}

		if (!is_dir($path)) {
			return false;
		}

		foreach ($namespace_components as $name) {
			$scaned_items = scandir($path);
			if (!in_array($name, $scaned_items, true)) {
				// try the lower case name
				$name = strtolower($name);
				if (!in_array($name, $scaned_items, true)) {
					return false;
				}
			}

			$path .= $name . DS;
			$dir_components[] = $name;
		}

		return join(DS, $dir_components);
	}

	private function render_all()
	{
		self::echo_start('Rendering programs...', LF);

		$header_coder = new PHPLoaderCoder();

		// prepare dist namespace
		$this->unit->dist_ns_uri = $header_coder->render_namespace_identifier($this->unit->ns);

		// prepare the prefix of namespace, for generate the autoloads classmap
		if ($this->unit->dist_ns_uri) {
			$this->unit_dist_ns_prefix = $this->unit->dist_ns_uri . PHPCoder::NS_SEPARATOR;
		}

		// prepare the depends relation
		foreach ($this->normal_programs as $program) {
			foreach ($program->declarations as $node) {
				if ($node->is_unit_level && !$node instanceof ClassKindredDeclaration) {
					ASTHelper::set_depends_to_unit_level($node);
				}
			}
		}

		// native programs, just collect autoloads
		foreach ($this->native_programs as $program) {
			$ns_prefix = $program->ns ? PHPCoder::ns_to_string($program->ns) . PHPCoder::NS_SEPARATOR : null;
			$this->collect_autoloads($program, $program->file, $ns_prefix);
		}

		// tea programs, render and collect autoloads
		foreach ($this->normal_programs as $program) {
			$dist_file_path = $this->render_program($program);
			if ($dist_file_path) {
				$this->collect_autoloads($program, $dist_file_path, $this->unit_dist_ns_prefix);
			}
		}

		// the loader file for supply to other units
		// the global Constants and Functions would be render to the loader file
		$this->render_loader_file($header_coder);

		// the public header for supply to other units
		$this->render_public_header();

		self::echo_success(count($this->normal_programs) . ' programs rendered.');
	}

	private function render_loader_file(PHPLoaderCoder $coder)
	{
		if (file_exists($this->unit_loader_file)) {
			$existing_loader = file_get_contents($this->unit_loader_file);
			if ($existing_loader !== false && strpos($existing_loader, PHPLoaderMaker::GENERATES_TAG) !== false) {
				PHPLoaderMaker::generate_loader_file($this->unit_path, $this->autoloads_map, $this->unit->dist_ns_uri);
				return;
			}
		}

		$dist_code = $coder->render_loader_program($this->header_program, $this->normal_programs, $this->loaders);

		// the autoloads for classes/interfaces/traits
		$dist_code .= $coder->render_autoloads_code($this->autoloads_map);

		file_put_contents($this->unit_loader_file, $dist_code);
	}

	private function render_program(Program $program)
	{
		$dist_file = $this->get_dist_file_path($program->name);

		if ($program->declarations or $program->initializer) {
			$coder = new PHPCoder();
			$decl_dist_code = $coder->render_program($program);
			file_put_contents($dist_file, $decl_dist_code);
		}
		else {
			file_exists($dist_file) and unlink($dist_file);
			$dist_file = null;
		}

		return $dist_file;
	}

	private function get_dist_file_path(string $name)
	{
		if ($this->is_src_dir) {
			$name = substr($name, 4); // trim the 'src/' prefix
		}

		$file_path = $this->unit_dist_path . $name . '.' . PHP_EXT_NAME;

		$dir_path = dirname($file_path);
		if (!is_dir($dir_path)) {
			FileHelper::mkdir($dir_path);
		}

		return $file_path;
	}

	private function render_public_header()
	{
		$program = new Program(PUBLIC_HEADER_NAME, $this->unit);
		$program->uses = $this->header_program->uses;

		foreach ($this->unit->symbols as $symbol) {
			$decl = $symbol->declaration;
			$belong_program = $decl->program;
			$modifier = $decl->modifier ?? null;
			if ($modifier === _PUBLIC && $belong_program->unit === $this->unit) {
				// treat as extern declaration
				if ($belong_program->is_external) {
					$decl->is_extern = true;
				}

				$program->append_declaration($decl);
			}
		}

		$code = $program->render(new TeaHeaderCoder());

		file_put_contents($this->unit_public_file, $code);
	}

	private function collect_autoloads(Program $program, string $dist_file_path, ?string $name_prefix = null)
	{
		if ($name_prefix !== null) {
			$name_prefix = ltrim($name_prefix, _BACK_SLASH);
		}

		$dist_file_path = substr($dist_file_path, $this->unit_path_prefix_len);

		foreach ($program->declarations as $node) {
			if ($node instanceof ClassKindredDeclaration
				&& !$node instanceof BuiltinTypeClassDeclaration
				// && !$node->is_extern
			) {
				$name = $name_prefix . $node->name;
				$this->autoloads_map[$name] = $dist_file_path;

				// intertraits
				if ($node instanceof IntertraitDeclaration) {
					$trait_name = PHPCoder::get_intertrait_trait_name($name);
					$this->autoloads_map[$trait_name] = $dist_file_path;
				}
			}
		}
	}

	private function scan_program_files(string $dir_path, string $catching_ext)
	{
		$items = scandir($dir_path);

		$files = [];
		$subdirs = [];
		foreach ($items as $item) {
			if (in_array($item, DIR_SCAN_SKIP_ITEMS, true)) {
				continue;
			}

			$item = $dir_path . $item;
			if (is_dir($item)) {
				if ($this->is_package_dir($item)) {
					// ignore the sub package
					$this->echo_warring("Folder '$item' seems a subpackage, ignored...");
				}
				else {
					$subdirs[] = $item;
				}

				continue;
			}

			$ext_pos = strrpos($item, '.');
			if (!$ext_pos) {
				continue;
			}

			$current_ext = substr($item, $ext_pos + 1);
			if ($current_ext === $catching_ext) {
				$files[] = $item;
			}
			elseif (strtolower($current_ext) === $catching_ext) {
				// the valid ext-names must be named in lower case
				$this->end_error("Please use a lower case extension name for file '{$item}'");
			}
		}

		// scan the sub-folders
		foreach ($subdirs as $item) {
			$sub_files = $this->scan_program_files($item . DS, $catching_ext);
			$files = array_merge($files, $sub_files);
		}

		return $files;
	}

	private function is_package_dir(string $path)
	{
		return file_exists($path . UNIT_HEADER_FILE_NAME) || file_exists($path . PUBLIC_HEADER_FILE_NAME);
	}

	private function parse_tea_header(string $file, ASTFactory $ast_factory)
	{
		$parser = new HeaderParser($ast_factory, $file);
		$program = $parser->read_program();
		// Collect errors but don't throw yet
		if ($parser->has_parse_errors()) {
			$this->collected_parse_errors = array_merge($this->collected_parse_errors, $parser->get_parse_errors());
		}
		return $program;
	}

	private function parse_tea_program(string $file, ASTFactory $ast_factory)
	{
		$parser = new TeaParser($ast_factory, $file);
		$program = $parser->read_program();
		// Collect errors but don't throw yet
		if ($parser->has_parse_errors()) {
			$this->collected_parse_errors = array_merge($this->collected_parse_errors, $parser->get_parse_errors());
		}
		return $program;
	}

	private function parse_php_program(string $file)
	{
		$parser = new PHPParser($this->ast_factory, $file);
		$program = $parser->read_program();
		// Collect errors but don't throw yet
		if ($parser->has_parse_errors()) {
			$this->collected_parse_errors = array_merge($this->collected_parse_errors, $parser->get_parse_errors());
		}
		return $program;
	}

	private static function echo_start(string $message, string $ending = "\t")
	{
		echo $message, $ending;
	}

	private static function echo_success(string $message = 'success.')
	{
		echo $message, "\n";
	}

	private static function echo_warring(string $message)
	{
		echo "\nWarring: ", $message, "\n\n";
	}

	private static function end_error(string $message)
	{
		throw new Exception("Error: {$message}");
	}
}

// program end
