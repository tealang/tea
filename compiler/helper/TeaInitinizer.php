<?php
namespace Tea;

use Exception;

class TeaInitinizer
{
	public function __construct(string $uri)
	{
		$uri = FileHelper::normalize_path($uri);
		$this->check_namespace_uri($uri);

		$this->uri = $uri;
	}

	public function process()
	{
		$dir = $this->uri;
		if (!file_exists($dir)) {
			FileHelper::mkdir($dir);
		}

		// the __unit.th
		$header_file = $dir . '/__unit.th';
		file_put_contents($header_file, "\n#unit {$this->uri}\n\n");

		// the main.tea
		$main_file = $dir . '/main.tea';
		file_put_contents($main_file, "\necho 'Hello!'\n\n// end\n");
	}

	public static function check_namespace_uri(string $uri)
	{
		$components = explode(DS, $uri);

		$first_name = $components[0];
		if (!self::check_unit_uri_domain($first_name)) {
			throw new Exception("Invalid URI name '$uri' for declare Unit.");
		}

		for ($i = 1; $i < count($components); $i++) {
			$name = $components[$i];
			if (!TeaHelper::is_subnamespace_name($name)) {
				throw new Exception("Invalid URI name '$uri' for declare Unit.");
			}
		}

		return true;
	}

	private static function check_unit_uri_domain(string $domain)
	{
		$components = explode(_DOT, $domain);
		for ($i = 0; $i < count($components); $i++) {
			$name = $components[$i];
			if (!TeaHelper::is_domain_component($name)) {
				return false;
			}
		}

		return true;
	}
}

