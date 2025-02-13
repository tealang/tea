<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class PHPLoaderMaker
{
	const GENERATES_TAG = '# --- generates ---';

	public static function generate_loader_file(string $path, array $autoloads, string $namespace = null)
	{
		$loader_file = $path . PUBLIC_LOADER_FILE_NAME;
		$warring = '// Please do not modify the following contents';

		if (!file_exists($loader_file)) {
			static::init_loader_file($loader_file, $namespace, $warring);
		}

		$autoloads = self::render_autoloads_code($autoloads, '__DIR__ . DIRECTORY_SEPARATOR');

		// write file
		$contents = file_get_contents($loader_file);
		if (strpos($contents, self::GENERATES_TAG)) {
			$contents = preg_replace('/\n' . self::GENERATES_TAG . '[\w\W]+/', $autoloads, $contents);
			if (!$contents) {
				throw new Exception("Unexpected error on replaceing generated contents.");
			}
		}
		else {
			$contents .= "{$warring}{$autoloads}";
		}

		file_put_contents($loader_file, $contents);
	}

	public static function render_autoloads_code(array $autoloads, string $unit_path)
	{
		$tag = self::GENERATES_TAG;
		$autoloads = static::stringify_autoloads($autoloads);

		return "
{$tag}
const __AUTOLOADS = {$autoloads};

spl_autoload_register(function (\$class) {
	isset(__AUTOLOADS[\$class]) && require $unit_path . __AUTOLOADS[\$class];
});

// end
";
	}

	protected static function init_loader_file(string $loader_file, ?string $namespace, string $warring)
	{
		$tag = self::GENERATES_TAG;

		if ($namespace) {
			$namespace = "namespace $namespace;\n";
		}

		file_put_contents($loader_file, "<?php
{$namespace}
const UNIT_PATH = __DIR__ . DIRECTORY_SEPARATOR;

{$warring}
{$tag}
");
	}

	protected static function stringify_autoloads(array $autoloads)
	{
		$items = [];
		foreach ($autoloads as $class => $file) {
			$items[] = "'$class' => '$file'";
		}

		$items = join(",\n\t", $items);

		return "[
	$items
]";
	}
}
