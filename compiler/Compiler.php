<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class Compiler
{
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
	private $workspace_path;

	// header file path of current unit
	private $header_file_path;

	private $search_paths;

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

	// /**
	//  * @var bool
	//  */
	// private $is_mixed_mode = false;

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

	// the builtin symbols cache
	private $builtin_symbols;

	// autoload classes/interfaces/traits for render Autoload
	private $autoloads_map = [];

	// current unit is builtin-libs unit or not?
	// private $target_is_builtin = false;

	public function __construct(string $unit_path)
	{
		ASTFactory::init_ast_system();

		$this->init_unit($unit_path);

		// when not on build the builtins unit
		if (!self::check_is_builtin_unit($unit_path)) {
			$this->load_builtins();
		}
	}

	private function init_unit(string $unit_path)
	{
		$this->header_file_path = $unit_path . UNIT_HEADER_FILE_NAME;
		if (!file_exists($this->header_file_path)) {
			throw new Exception("The Unit header file '{$this->header_file_path}' not found.");
		}

		// init unit
		$this->unit = new Unit($unit_path);
		$this->ast_factory = new ASTFactory($this->unit);

		$this->unit_path = $unit_path;
	}

	private const CASE_REGARDLESS_OS_LIST = ['Darwin', 'Windows', 'WINNT', 'WIN32'];

	private static function check_is_builtin_unit(string $unit_path)
	{
		// some Operation Systems are Case regardless
		if (in_array(PHP_OS, self::CASE_REGARDLESS_OS_LIST)) {
			$result = strtolower(BUILTIN_PATH) === strtolower($unit_path);
		}
		else {
			$result = BUILTIN_PATH === $unit_path;
		}

		return $result;
	}

	public function make()
	{
		// for generate the autoload maps
		$this->unit_path_prefix_len = strlen($this->unit_path);

		$scan_path = $this->unit_path;

		// if has "src" directory, ignore others
		if (file_exists($scan_path . SRC_DIR_NAME)) {
			$scan_path .= SRC_DIR_NAME . DS;
			$this->is_src_dir = true;
		}

		$this->scan_program_files($scan_path);

		// $this->is_mixed_mode = !empty($this->native_program_files);

		$this->parse_unit_header();

		$this->parse_programs();

		// bind the builtin type symbols
		TypeFactory::set_symbols($this->unit);

		$this->load_dependences($this->unit);

		$this->check_ast();

		$this->prepare_for_render();

		// prepare for render
		$header_coder = new PHPLoaderCoder();
		$this->unit->dist_ns_uri = $header_coder->render_namespace_identifier($this->unit->ns);
		if ($this->unit->dist_ns_uri) {
			// use to generate the autoloads classmap
			$this->unit_dist_ns_prefix = $this->unit->dist_ns_uri . PHPCoder::NS_SEPARATOR;
		}

		// render programs
		$this->render_all();

		// the global Constants and Functions would be render to the loader file
		$this->render_loader_file($header_coder);

		$this->render_public_header();

		return count($this->normal_programs);
	}

	private function prepare_for_render()
	{
		// for include expression
		$this->unit->include_prefix = DIST_DIR_NAME . DS;

		// for faster processing the operations
		OperatorFactory::set_render_options(PHPCoder::OPERATOR_MAP, PHPCoder::OPERATOR_PRECEDENCES);
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

			self::echo_success(count($this->native_programs) . ' PHP programs parsed success.');
		}

		// parse tea programs
		foreach ($this->normal_program_files as $file) {
			$this->normal_programs[] = $this->parse_tea_program($file);
		}

		self::echo_success(count($this->normal_programs) . ' Tea programs parsed success.' . LF);
	}

	private function parse_unit_header()
	{
		// parse header file
		$this->header_program = $this->parse_tea_header($this->header_file_path, $this->ast_factory);

		// check #unit is defined
		if (!$this->unit->ns) {
			throw new Exception("#unit declaration not found in Unit header file: {$this->header_file_path}");
		}

		$this->prepare_paths();
	}

	private function prepare_paths()
	{
		$unit = $this->unit;

		$checking_path = $this->unit_path;
		foreach ($unit->ns->names as $ns_name) {
			$dir_name = basename($checking_path);
			$checking_path = dirname($checking_path);
		}

		// the super dir levels for the Unit
		// for generate the $super_path
		$unit->super_dir_levels = count($unit->ns->names);

		if (self::is_framework_internal_namespaces($unit->ns)) {
			$this->container_name = basename($checking_path);
			$checking_path = dirname($checking_path);
			$unit->super_dir_levels += 1;
		}
		else {
			$this->container_name = $dir_name;
		}

		// paths for search dependences
		$search_paths = [];
		$super_dir_name = basename($checking_path);
		if (in_array($super_dir_name, ['units', 'vendor'])) {
			$checking_path = dirname($checking_path);
			if ($super_dir_name === 'units') {
				$search_paths[] = $checking_path;
				$search_paths[] = $checking_path . '/vendor/';
			}
			elseif ($super_dir_name === 'vendor') {
				$search_paths[] = $checking_path;
				$search_paths[] = $checking_path . '/units/';
			}

			$unit->super_dir_levels += 1;
		}
		else {
			$search_paths[] = $checking_path . '/units/';
			$search_paths[] = $checking_path . '/vendor/';
		}

		array_unshift($search_paths, $checking_path . DS);
		$this->search_paths = $search_paths;

		// set the dist paths
		$this->unit_dist_path = $this->unit_path . DIST_DIR_NAME . DS;
		$this->unit_public_file = $this->unit_path . PUBLIC_HEADER_FILE_NAME;
		$this->unit_loader_file = $this->unit_path . PUBLIC_LOADER_FILE_NAME;
	}

	private function check_ast()
	{
		self::echo_start('Checking...', LF);

		ASTChecker::init_checkers($this->unit);

		// check depends units first
		foreach ($this->unit->use_units as $dep_unit) {
			$normal_checker = ASTChecker::get_checker($dep_unit->programs[PUBLIC_HEADER_NAME]);
			$this->check_ast_for_unit($dep_unit, $normal_checker);
		}

		// check current unit
		$normal_checker = ASTChecker::get_checker($this->header_program);
		$this->check_ast_for_unit($this->unit, $normal_checker);

		self::echo_success('Programs checked success.' . LF);
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

	private function load_builtins()
	{
		self::echo_start('Loading builtins...');

		$program = $this->parse_tea_program(BUILTIN_PROGRAM);
		$program->unit = null;

		$this->builtin_symbols = $program->symbols;

		self::echo_success();
	}

	private function load_dependences(Unit $unit)
	{
		$current_unit_uri = $this->unit->ns->uri;

		foreach ($unit->use_units as $uri => $target) {
			if ($target instanceof Unit) {
				//
			}
			elseif ($target->uri !== '' and $target->uri !== $current_unit_uri) {
				$use_unit = $this->load_unit($target);

				// 框架内部名称空间的，无需添加载入语句，由框架加载即可
				$use_unit->is_need_load = !self::is_framework_internal_namespaces($use_unit->ns);

				$unit->use_units[$uri] = $use_unit;
			}
			else {
				unset($unit->use_units[$uri]);
			}
		}
	}

	private function load_unit(NamespaceIdentifier $ns): Unit
	{
		$uri = $ns->uri;

		if (isset($this->units_pool[$uri])) {
			return $this->units_pool[$uri];
		}

		[$unit_path, $unit_dir] = $this->find_unit_public_dir($ns);
		$unit_public_file = $unit_path . PUBLIC_HEADER_FILE_NAME;

		// check public file
		if (!file_exists($unit_public_file)) {
			throw new Exception("The public file of unit '{$ns->uri}' not found at: $unit_path");
		}

		$unit = new Unit($unit_path);
		$this->units_pool[$uri] = $unit; // add to pool, so do not need to reload

		// for render require_once statments
		$unit->loader_file = $unit_dir . '/' . PUBLIC_LOADER_FILE_NAME;

		$unit->symbols = $this->builtin_symbols;

		$ast_factory = new ASTFactory($unit);
		$this->parse_tea_header($unit_public_file, $ast_factory);

		// 递归载入依赖
		$this->load_dependences($unit);

		return $unit;
	}

	private function find_unit_public_dir(NamespaceIdentifier $ns)
	{
		$names = $ns->names;

		// add the project name when the Unit in a framework
		if (self::is_framework_internal_namespaces($ns)) {
			array_unshift($names, $this->container_name);
		}

		foreach ($this->search_paths as $path) {
			if (!is_dir($path)) {
				continue;
			}

			$result = $this->try_look_dir_in_path($path, $names);
			if ($result) {
				break;
			}
		}

		if ($result === false) {
			$paths = join(', and ', $this->search_paths);
			throw new Exception("The directory of depends unit '{$ns->uri}' not found in $paths");
		}

		// use the real dir names for render
		return $result;
	}

	private function try_look_dir_in_path(string $path, array $dir_names)
	{
		foreach ($dir_names as $name) {
			$scan_names = scandir($path);

			// try the origin name in first
			if (!in_array($name, $scan_names, true)) {
				// try the lower case name
				$name = strtolower($name);
				if (!in_array($name, $scan_names, true)) {
					return false;
				}
			}

			$path .= $name . DS;
			$real_names[] = $name;
		}

		return [$path, join(DS, $real_names)];
	}

	private function render_all()
	{
		self::echo_start('Rendering programs...');

		// the depends relation
		foreach ($this->normal_programs as $program) {
			foreach ($program->declarations as $node) {
				if ($node->is_unit_level && !$node instanceof ClassKindredDeclaration) {
					$node->set_depends_to_unit_level();
				}
			}
		}

		foreach ($this->native_programs as $program) {
			$ns_prefix = $program->ns ? PHPCoder::ns_to_string($program->ns) . PHPCoder::NS_SEPARATOR : null;
			$this->collect_autoloads_map($program, $program->file, $ns_prefix);
		}

		foreach ($this->normal_programs as $program) {
			$dist_file_path = $this->render_program($program);
			$this->collect_autoloads_map($program, $dist_file_path, $this->unit_dist_ns_prefix);
		}

		self::echo_success(count($this->normal_programs) . ' programs rendered success.');
	}

	private function render_loader_file(PHPCoder $coder)
	{
		$dist_code = $coder->render_loader_program($this->header_program, $this->normal_programs);

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
		self::echo_start('Rendering public header file...');

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

		$count = count($program->declarations);
		self::echo_success("$count public declarations rendered success.");
	}

	private function collect_autoloads_map(Program $program, string $dist_file_path, string $name_prefix = null)
	{
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
			echo "\nWarring: The sub-diretory '$path' has be ignored, because of it has '__unit.th' or '__public.th'.\n\n";
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

	private static function is_framework_internal_namespaces(NamespaceIdentifier $ns)
	{
		return in_array($ns->names[0], FRAMEWORK_INTERNAL_NAMESPACES, true);
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
