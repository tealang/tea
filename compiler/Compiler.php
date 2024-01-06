<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

const
	BUILTIN_NS_URI = 'tea/builtin',
	BUILTIN_PATH = TEA_BASE_PATH . 'tea-modules/tea/builtin/',
	BUILTIN_PROGRAM = BUILTIN_PATH . 'core.tea';

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

	// the project dir name, or vendor/units
	// use to find the depencences
	private $container_name;

	// the workspace path to load units
	private $work_path;

	private $super_path;

	// for render code
	private $loaders = [];

	// header file path of current unit
	private $header_file_path;

	private $search_dirs = ['', 'units', 'vendor'];

	/**
	 * @var Program
	 */
	private $header_program;

	/**
	 * @var Program[]
	 */
	private $normal_programs = [];

	/**
	 * @var Program[]
	 */
	private $native_programs = [];

	/**
	 * @var string
	 */
	private $normal_program_files = [];

	/**
	 * @var string
	 */
	private $native_program_files = [];

	private $is_src_dir = false;

	/**
	 * the instance of current Unit
	 * @var Unit
	 */
	private $unit;

	// the dependence units cache
	private $units_pool = [];

	/**
	 * @var ASTFactory
	 */
	private $ast_factory;


	private $builtin_unit;

	// the builtin symbols cache
	private $builtin_symbols;

	// autoload classes/interfaces/traits for render Autoload
	private $autoloads_map = [];

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
			throw new Exception("The Unit header file '{$this->header_file_path}' not found.");
		}

		// init unit
		$this->unit = new Unit($unit_path);
		$this->ast_factory = new ASTFactory($this->unit);

		$this->unit_path = $unit_path;

		// when not on build the builtins unit
		if (!self::check_is_builtin_unit($unit_path)) {
			// $this->load_builtins();
			$this->load_builtin_unit();
		}
	}

	private function load_builtin_unit(string $unit_path = BUILTIN_PATH)
	{
		$unit_public_file = $unit_path . PUBLIC_HEADER_FILE_NAME;

		$this->builtin_unit = new Unit($unit_path);

		$ast_factory = new ASTFactory($this->builtin_unit);

		$parser = new TeaParser($ast_factory, BUILTIN_PROGRAM);
		$program = $parser->read_program();

		// lets render namespace as root
		$program->unit = null;

		// $this->parse_tea_header($unit_public_file, $ast_factory);
	}

	// private function load_builtins()
	// {
	// 	self::echo_start('Loading builtins...', LF);

	// 	$program = $this->parse_tea_program(BUILTIN_PROGRAM);
	// 	$program->unit = null;

	// 	$this->builtin_symbols = $program->symbols;
	// }

	private static function check_is_builtin_unit(string $unit_path)
	{
		// some Operation Systems are Case regardless
		if (in_array(PHP_OS, ['Darwin', 'Windows', 'WINNT', 'WIN32'])) {
			$result = strtolower(BUILTIN_PATH) === strtolower($unit_path);
		}
		else {
			$result = BUILTIN_PATH === $unit_path;
		}

		return $result;
	}

	public function make(string $unit_path)
	{
		$this->init_unit($unit_path);

		// for generate the autoload maps
		$this->unit_path_prefix_len = strlen($this->unit_path);

		$scan_path = $this->unit_path;

		// if has "src" directory, ignore others
		if (file_exists($scan_path . SRC_DIR_NAME)) {
			$scan_path .= SRC_DIR_NAME . DS;
			$this->is_src_dir = true;
		}

		$this->scan_program_files($scan_path);

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

	private function parse_programs()
	{
		self::echo_start('Parsing programs...', LF);

		$unit_uri = $this->unit->ns->uri;

		// parse native programs
		if ($this->native_program_files) {
			foreach ($this->native_program_files as $file) {
				$program = $this->parse_php_program($file);
				if (!$program->ns or $program->ns->uri !== $unit_uri) {
					$program->is_external = true;
					continue;
				}

				$this->native_programs[] = $program;
			}

			self::echo_success(count($this->native_programs) . ' PHP programs parsed.');
		}

		// parse tea programs
		foreach ($this->normal_program_files as $file) {
			$this->normal_programs[] = $this->parse_tea_program($file);
		}

		self::echo_success(count($this->normal_programs) . ' Tea programs parsed.' . LF);
	}

	private function parse_unit_header()
	{
		// parse header file
		$this->header_program = $this->parse_tea_header($this->header_file_path, $this->ast_factory);

		// check #unit is defined
		if (!$this->unit->ns) {
			throw new Exception("#unit declaration not found in Unit header file: {$this->header_file_path}");
		}

		$this->prepare_unit_paths();
	}

	private function prepare_unit_paths()
	{
		$based_level = count($this->unit->ns->names) - 1; // the first is ''
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
		self::echo_start('Checking...', LF);

		ASTChecker::init_checkers($this->unit, $this->builtin_unit);

		// check depends units first
		foreach ($this->unit->use_units as $dep_unit) {
			$normal_checker = ASTChecker::get_checker($dep_unit->programs[PUBLIC_HEADER_NAME]);
			$this->check_ast_for_unit($dep_unit, $normal_checker);
		}

		// check current unit
		$normal_checker = ASTChecker::get_checker($this->header_program);
		$this->check_ast_for_unit($this->unit, $normal_checker);

		self::echo_success('Programs checked.' . LF);
	}

	private function check_ast_for_unit(Unit $unit, ASTChecker $normal_checker)
	{
		// collect uses targets for Tea programs
		foreach ($unit->programs as $program) {
			// if (!$program->is_native) {
				$normal_checker->collect_program_uses($program);
			// }
		}

		$native_checker = ASTChecker::get_native_checker();
		// // the native programs
		// foreach ($this->native_programs as $program) {
		// 	self::echo_start(" - {$program->file}", LF);
		// 	$native_checker->check_program($program);
		// }

		// the Native programs
		foreach ($unit->programs as $program) {
			if ($program->is_native) {
				self::echo_start(" - {$program->file}", LF);
				$native_checker->check_program($program);
			}
		}

		// the Tea programs
		foreach ($unit->programs as $program) {
			if (!$program->is_native) {
				self::echo_start(" - {$program->file}", LF);
				$normal_checker->check_program($program);
			}
		}
	}

	private function load_dependences_for_unit(Unit $unit)
	{
		$current_unit_uri = $this->unit->ns->uri;

		foreach ($unit->use_units as $uri => $target) {
			if ($target instanceof Unit) {
				//
			}
			elseif ($target->uri !== '' and $target->uri !== $current_unit_uri) {
				$use_unit = $this->load_unit_for_namespace($target);
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
		// 	throw new Exception("The public file of unit '{$ns->uri}' not found at: $unit_public_file");
		// }

		$unit = new Unit($unit_path);

		// This mechanism is not flexible, change to the basic namespace mechanism
		$unit->symbols = $this->builtin_symbols;

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
			throw new Exception("The depends unit '{$ns->uri}' not found in ('{$dirs}')");
		}

		return [$path_based_type, $relative_path];
	}

	private function try_search_all_dirs(array $names, string $searching_path)
	{
		if ($names[0] === '') {
			array_shift($names);
		}

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

		// prepare for include expression
		$this->unit->include_prefix = DIST_DIR_NAME . DS;

		// prepare for faster processing the operations
		OperatorFactory::set_render_options(PHPCoder::OPERATOR_MAP, PHPCoder::OPERATOR_PRECEDENCES);

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
					$node->set_depends_to_unit_level();
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
			$this->collect_autoloads($program, $dist_file_path, $this->unit_dist_ns_prefix);
		}

		// the loader file for supply to other units
		// the global Constants and Functions would be render to the loader file
		$this->render_loader_file($header_coder);

		// the public header for supply to other units
		$this->render_public_header();

		self::echo_success(count($this->normal_programs) . ' programs rendered.');
	}

	private function render_loader_file(PHPCoder $coder)
	{
		$dist_code = $coder->render_loader_program($this->header_program, $this->normal_programs, $this->loaders);

		// the autoloads for classes/interfaces/traits
		$dist_code .= PHPLoaderCoder::render_autoloads_code($this->autoloads_map);

		file_put_contents($this->unit_loader_file, $dist_code);
	}

	private function render_program(Program $program)
	{
		$dist_file = $this->get_dist_file_path($program->name);

		$coder = new PHPCoder();
		$dist_code = $coder->render_program($program);

		file_put_contents($dist_file, $dist_code);
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
			$declaration = $symbol->declaration;
			$belong_program = $declaration->program;
			if (isset($declaration->modifier) && $declaration->modifier === _PUBLIC
				&& $belong_program->unit === $this->unit
				&& !$belong_program->is_external
			) {
				$program->append_declaration($declaration);
			}
		}

		$code = $program->render(new TeaHeaderCoder());

		file_put_contents($this->unit_public_file, $code);
	}

	private function collect_autoloads(Program $program, string $dist_file_path, string $name_prefix = null)
	{
		if ($name_prefix !== null) {
			$name_prefix = ltrim($name_prefix, _BACK_SLASH);
		}

		$dist_file_path = substr($dist_file_path, $this->unit_path_prefix_len);

		foreach ($program->declarations as $node) {
			if ($node instanceof ClassKindredDeclaration && !$node instanceof BuiltinTypeClassDeclaration && $node->label !== _PHP) {
				$name = $name_prefix . $node->name;

				$this->autoloads_map[$name] = $dist_file_path;

				// 接口的伴生Trait
				if ($node instanceof InterfaceDeclaration && $node->has_default_implementations) {
					$trait_name = $name . 'Trait';
					$this->autoloads_map[$trait_name] = $dist_file_path;
				}
			}
		}
	}

	private function scan_program_files(string $path, int $levels = 0)
	{
		$items = scandir($path);

		// check is a sub-unit
		if ($levels > 0 && (in_array(UNIT_HEADER_FILE_NAME, $items) || in_array(PUBLIC_HEADER_FILE_NAME, $items)) ) {
			echo "\nWarring: The sub-diretory '$path' is ignored, because of it has '__unit.th' or '__public.th'.\n\n";
			return; // ignore these sub-unit
		}

		foreach ($items as $item) {
			if (in_array($item, DIR_SCAN_SKIP_ITEMS, true)) {
				continue;
			}

			$item = $path . $item;

			// scan the sub-diretories
			if (is_dir($item)) {
				$this->scan_program_files($item . DS, $levels++);
				continue;
			}

			$ext_pos = strrpos($item, '.');
			if (!$ext_pos) {
				continue;
			}

			$ext_name = substr($item, $ext_pos + 1);

			// the valid ext-names must be named in lower-case
			// ignore the upper-case ext-names
			if ($ext_name === TEA_EXT_NAME) {
				$this->normal_program_files[] = $item;
			}
			elseif ($ext_name === PHP_EXT_NAME) {
				$this->native_program_files[] = $item;
			}
			else {
				// ignore other files ...
			}
		}
	}

	private function parse_tea_header(string $file, ASTFactory $ast_factory)
	{
		$parser = new HeaderParser($ast_factory, $file);
		return $parser->read_program();
	}

	private function parse_tea_program(string $file)
	{
		$parser = new TeaParser($this->ast_factory, $file);
		return $parser->read_program();
	}

	private function parse_php_program(string $file)
	{
		$parser = new PHPParserLite($this->ast_factory, $file);
		return $parser->read_program();
	}

	private static function echo_start(string $message, string $ending = "\t")
	{
		echo $message, $ending;
	}

	private static function echo_success(string $message = 'success.')
	{
		echo $message, LF;
	}
}

// program end
