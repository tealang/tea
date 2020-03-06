<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

const TEA_EXT_NAME = 'tea', TEA_HEADER_EXT_NAME = 'th', PHP_EXT_NAME = 'php',
	UNIT_HEADER_NAME = '__unit', PUBLIC_HEADER_NAME = '__public',
	DIST_DIR_NAME = 'dist';

class Compiler
{
	const BUILTIN_PATH = BASE_PATH . 'builtin/';

	const BUILTIN_PROGRAM = self::BUILTIN_PATH . 'core.tea';

	const BUILTIN_LOADING_FILE = 'tea/builtin/dist/__public.php';

	const UNIT_HEADER_FILE_NAME = UNIT_HEADER_NAME . '.' . TEA_HEADER_EXT_NAME;

	const PUBLIC_HEADER_FILE_NAME = PUBLIC_HEADER_NAME . '.' . TEA_HEADER_EXT_NAME;

	const PUBLIC_LOADER_FILE_NAME = PUBLIC_HEADER_NAME . '.' . PHP_EXT_NAME;

	// the special namespaces for some framework
	const FRAMEWORK_INTERNAL_NAMESPACES = ['App', 'Model', 'Lib'];

	// the path of current compiling unit
	private $unit_path;

	// the built results path
	private $unit_dist_path;

	// the prefix length use to trim path prefix
	private $unit_dist_path_len;

	// use to generate the autoloads classmap
	private $unit_dist_ns_prefix;

	private $unit_public_file;

	private $unit_dist_loader_file;

	// the project path of current unit
	// use to find the depencence units in current project, and find the unit_dist_path
	private $base_path;

	// the workspace path of current unit
	// use to find the depencence units in foriegn projects
	private $super_path;

	// header file path of current unit
	private $header_file_path;

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

	/**
	 * @var bool
	 */
	private $is_mixed_mode = false;

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

	// autoload classes/interfaces/traits for render php loading code
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
		$this->header_file_path = $unit_path . self::UNIT_HEADER_FILE_NAME;
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
			$result = strtolower(self::BUILTIN_PATH) === strtolower($unit_path);
		}
		else {
			$result = self::BUILTIN_PATH === $unit_path;
		}

		return $result;
	}

	public function make()
	{
		$this->scan_program_files($this->unit_path);

		$this->is_mixed_mode = !empty($this->native_program_files);

		$this->parse_unit_header();

		$this->parse_programs();

		// bind the builtin type symbols
		TypeFactory::set_symbols($this->unit);

		$this->load_dependences($this->unit);

		$this->check_ast();

		$this->prepare_for_render();

		// prepare for render
		$header_coder = new PHPCoder();
		$this->unit->dist_ns_uri = $header_coder->render_namespace_identifier($this->unit->ns);
		if ($this->unit->dist_ns_uri) {
			// use to generate the autoloads classmap
			$this->unit_dist_ns_prefix = $this->unit->dist_ns_uri . PHPCoder::NS_SEPARATOR;
		}

		// render programs
		$this->render_all();

		// the global constants and functions would be render to the loading file
		$this->render_unit_header($header_coder);

		$this->render_public_declarations();

		return count($this->normal_programs);
	}

	private function prepare_for_render()
	{
		// 当前Unit的dist目录往上的层数
		$super_dir_levels = count($this->unit->ns->names) + 1;

		if (self::is_framework_internal_namespaces($this->unit->ns)) {
			$super_dir_levels++;
		}

		if ($this->is_mixed_mode) {
			// for include expression
			$this->unit->include_prefix = DIST_DIR_NAME . DS;
			$this->unit->super_dir_levels = $super_dir_levels - 1;

			$this->unit->is_mixed_mode = true;
		}
		else {
			$this->unit->super_dir_levels = $super_dir_levels;
		}

		// for faster processing the operations
		OperatorFactory::set_render_options(PHPCoder::OPERATOR_MAP, PHPCoder::OPERATOR_PRECEDENCES);
	}

	private function parse_programs()
	{
		self::echo_start('Parsing programs...');

		foreach ($this->native_program_files as $file) {
			$this->native_programs[] = $this->parse_php_program($file);
		}

		foreach ($this->normal_program_files as $file) {
			$this->normal_programs[] = $this->parse_tea_program($file);
		}

		// check the global uses
		foreach ($this->unit->programs as $program) {
			$this->process_program_uses($program);
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

		// require the Unit namespace
		$this->prepare_paths($this->unit->ns);
	}

	private function prepare_paths(NamespaceIdentifier $ns)
	{
		$reversed_ns_names = array_reverse($ns->names);
		$dir_names = [];
		$checking_path = $this->unit_path;
		foreach ($reversed_ns_names as $ns_name) {
			$dir_name = basename($checking_path);
			if ($ns_name !== $dir_name && strtolower($ns_name) !== $dir_name) {
				throw new Exception("The dir name '$dir_name' did not matched the unit-namespace '$ns_name'.\nPlease rename to '$ns_name', or rename unit-namespace to '$dir_name'.");
			}

			$checking_path = dirname($checking_path);
			array_unshift($dir_names, $dir_name);
		}

		if (self::is_framework_internal_namespaces($ns)) {
			// 框架内部名称空间，其base_path应为往上一级
			$this->base_path = $checking_path . DS;
			$this->super_path = dirname($checking_path) . DS;
		}
		else {
			array_shift($dir_names);
			$this->base_path = $checking_path . DS . $dir_name . DS;
			$this->super_path = $checking_path . DS;
		}

		// {unit-path}/dist/
		$this->unit_dist_path = $this->unit_path . DIST_DIR_NAME . DS;

		// set the dist path
		if ($this->is_mixed_mode) {
			// mixed programming mode
			$this->unit_dist_path_len = strlen($this->unit_path);
			$this->unit_public_file = $this->unit_path . self::PUBLIC_HEADER_FILE_NAME;
			$this->unit_dist_loader_file = $this->unit_path . self::PUBLIC_LOADER_FILE_NAME;
		}
		else {
			// normal mode
			// $this->unit_dist_path = $this->find_unit_dist_path(join(DS, $dir_names));
			$this->unit_dist_path_len = strlen($this->unit_dist_path);
			$this->unit_public_file = $this->unit_dist_path . self::PUBLIC_HEADER_FILE_NAME;
			$this->unit_dist_loader_file = $this->get_dist_file_path(PUBLIC_HEADER_NAME);
		}
	}

	// private function find_unit_dist_path(string $dir)
	// {
	// 	// check project dist dir
	// 	$project_dist_path = $this->base_path . DIST_DIR_NAME;
	// 	if (!is_dir($project_dist_path)) {
	// 		throw new Exception("The project dist dir '{$project_dist_path}' not found.");
	// 	}

	// 	return $project_dist_path . DS . $dir . DS;
	// }

	private function process_program_uses(Program $program)
	{
		// for auto add use statement to dist codes

		$uses = [];
		foreach ($program->defer_check_identifiers as $identifier) {
			if (isset($program->symbols[$identifier->name])) {
				continue;
			}

			$symbol = $this->unit->symbols[$identifier->name] ?? null;
			if (!$symbol) {
				continue;
			}

			$declaration = $symbol->declaration;

			if ($declaration instanceof UseDeclaration) {
				// it should be a use statement in __unit

				$uri = $declaration->ns->uri;
				if ($declaration->target_name) {
					$uri .= '!'; // just to differentiate, avoid conflict with no targets use statements
				}

				// URI相同的将合并到一条
				if (!isset($uses[$uri])) {
					$uses[$uri] = new UseStatement($declaration->ns);
				}

				$uses[$uri]->targets[] = $declaration;
			}
			// elseif ($declaration instanceof ClassLikeDeclaration) {
			// 	if ($declaration->label === _PHP) {
			// 		// the declarations with #php
			// 		$uses[$symbol->name] = new UseStatement(new NamespaceIdentifier([$symbol->name]));
			// 	}
			// }
			// elseif ($declaration instanceof NamespaceDeclaration) {
			// 	// it should be a use statement in __unit
			// 	$uses[$symbol->name] = new UseStatement(new NamespaceIdentifier([$symbol->name]));
			// }
			elseif ($declaration instanceof FunctionDeclaration && $declaration->program->is_native) {
				$program->append_depends_native_program($declaration->program);
			}
		}

		// auto add use statement to programs
		if ($uses) {
			$program->uses = array_merge($program->uses, array_values($uses));
		}
	}

	private function check_ast()
	{
		self::echo_start('Checking AST...', LF);

		// check depends units first
		foreach ($this->unit->use_units as $dep_unit) {
			$this->check_ast_for_unit($dep_unit);
		}

		// check current unit
		$this->check_ast_for_unit($this->unit);

		self::echo_success('Tea programs checked.' . LF);
	}

	private function check_ast_for_unit(Unit $unit)
	{
		$checker = $unit->get_checker();
		foreach ($unit->programs as $program) {
			if ($program->is_native) {
				continue;
			}

			self::echo_start(" - {$program->file}", LF);
			$checker->check_program($program);
		}
	}

	private function load_builtins()
	{
		self::echo_start('Loading builtins...');

		$program = $this->parse_tea_program(self::BUILTIN_PROGRAM);
		$program->unit = null;

		$this->builtin_symbols = $program->symbols;

		self::echo_success();
	}

	private function load_dependences(Unit $unit)
	{
		foreach ($unit->use_units as $uri => $target) {
			if (!$target instanceof Unit) {
				$use_unit = $this->load_unit($target);

				// 框架内部名称空间由框架加载
				$use_unit->required_loading = !self::is_framework_internal_namespaces($use_unit->ns);

				$unit->use_units[$uri] = $use_unit;
			}
		}
	}

	private function load_unit(NamespaceIdentifier $ns): Unit
	{
		$uri = $ns->uri;

		if (isset($this->units_pool[$uri])) {
			return $this->units_pool[$uri];
		}

		$unit_dir = $this->find_unit_public_dir($ns);
		$unit_path = $this->super_path . $unit_dir . DS;
		$unit_public_file = $unit_path . self::PUBLIC_HEADER_FILE_NAME;

		$unit = new Unit($unit_path);
		$this->units_pool[$uri] = $unit; // add to pool, so do not need to reload

		// for render require_once statments
		$unit->loading_file = $unit_dir . '/' . self::PUBLIC_LOADER_FILE_NAME;

		$unit->symbols = $this->builtin_symbols;

		$ast_factory = new ASTFactory($unit);
		$this->parse_tea_header($unit_public_file, $ast_factory);

		// 递归载入依赖
		$this->load_dependences($unit);

		return $unit;
	}

	private function find_unit_public_dir(NamespaceIdentifier $ns)
	{
		$dir_names = $ns->names;

		// 框架内部的名称空间，上一层才是base_path
		if (self::is_framework_internal_namespaces($ns)) {
			$base_dir_name = basename($this->base_path);
			array_unshift($dir_names, $base_dir_name);
		}

		$try_paths = [];

		$unit_dir = $this->find_public_file_dir_with_names($dir_names, $try_paths);
		if ($unit_dir !== false) {
			return $unit_dir;
		}

		// find in dist directory
		// $first_name = $dir_names[0];
		// $dir_names[0] = DIST_DIR_NAME;
		// array_unshift($dir_names, $first_name);
		$dir_names[] = DIST_DIR_NAME;

		$unit_dir = $this->find_public_file_dir_with_names($dir_names, $try_paths);
		if ($unit_dir !== false) {
			return $unit_dir;
		}

		// error
		if ($try_paths) {
			$try_paths = join(', and ', $try_paths);
			$message = "The public file of unit '{$ns->uri}' not found in paths: $try_paths.";
		}
		else {
			$message = "The directory of unit '{$ns->uri}' not found.";
		}

		throw new Exception($message);
	}

	private function find_public_file_dir_with_names(array $dir_names, array &$try_paths)
	{
		$path = $this->super_path;

		// find dir
		$real_names = [];
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

		// check public file
		$unit_public_file = $path . self::PUBLIC_HEADER_FILE_NAME;
		if (file_exists($unit_public_file)) {
			// use the real dir names for render
			return join(DS, $real_names);
		}

		$try_paths[] = $unit_public_file;
		return false;
	}

	private function render_all()
	{
		self::echo_start('Rendering programs...');

		foreach ($this->native_programs as $program) {
			$ns_prefix = PHPCoder::ns_to_string($program->ns) . PHPCoder::NS_SEPARATOR;
			$this->collect_autoloads_map($program, $program->file, $ns_prefix);
		}

		foreach ($this->normal_programs as $program) {
			$dist_file_path = $this->render_program($program);
			$this->collect_autoloads_map($program, $dist_file_path, $this->unit_dist_ns_prefix);
		}

		self::echo_success(count($this->normal_programs) . ' programs rendered success.');
	}

	private function render_unit_header(PHPCoder $coder)
	{
		// $this->collect_autoloads_map($this->header_program, $this->unit_dist_loader_file, $this->unit_dist_ns_prefix);

		$dist_code = $coder->render_unit_header_program($this->header_program, $this->normal_programs);

		// the autoloads for classes
		$dist_code .= PHPLoaderMaker::render_autoloads_code($this->autoloads_map, _UNIT_PATH);

		file_put_contents($this->unit_dist_loader_file, $dist_code);
	}

	private function render_program(Program $program)
	{
		$dest_file = $this->get_dist_file_path($program->name);

		$coder = new PHPCoder();
		$dist_code = $coder->render_program($program);

		file_put_contents($dest_file, $dist_code);
		return $dest_file;
	}

	private function get_dist_file_path(string $name)
	{
		$file_path = $this->unit_dist_path . $name . '.' . PHP_EXT_NAME;

		$dir_path = dirname($file_path);
		if (!is_dir($dir_path)) {
			FileHelper::mkdir($dir_path);
		}

		return $file_path;
	}

	private function render_public_declarations()
	{
		self::echo_start('Rendering public declarations...');

		$program = new Program(PUBLIC_HEADER_NAME, $this->unit);

		foreach ($this->unit->symbols as $symbol) {
			$declaration = $symbol->declaration;
			if (isset($declaration->modifier)
				&& $declaration->modifier === _PUBLIC
				&& $declaration->program->unit === $this->unit) {
				$program->append_declaration($declaration);
			}
		}

		$code = $program->render(new PublicCoder());

		file_put_contents($this->unit_public_file, $code);

		$count = count($program->declarations);
		self::echo_success("$count declarations rendered success.");
	}

	private function collect_autoloads_map(Program $program, string $dist_file_path, string $name_prefix = null)
	{
		$dist_file_path = substr($dist_file_path, $this->unit_dist_path_len);

		foreach ($program->declarations as $node) {
			if ($node instanceof ClassLikeDeclaration && !$node instanceof BuiltinTypeClassDeclaration && $node->label !== _PHP) {
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

	const SCAN_SKIP_ITEMS = ['.', '..', 'dist', 'bin', '__public.php'];
	private function scan_program_files(string $path, int $levels = 0)
	{
		$items = scandir($path);

		if ($levels) {
			// check is a sub-unit
			if (array_search(self::UNIT_HEADER_FILE_NAME, $items) !== false
				|| array_search(self::PUBLIC_HEADER_FILE_NAME, $items) !== false) {
				echo "\nWarring: The sub-diretory '$path' has be ignored, because of it has '__unit.th' or '__public.th'.\n\n";
				return; // ignore these sub-unit
			}
		}

		foreach ($items as $item) {
			if (in_array($item, self::SCAN_SKIP_ITEMS, true)) {
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

			// we not care the upper-case ext-names
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
		return in_array($ns->names[0], self::FRAMEWORK_INTERNAL_NAMESPACES, true);
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
