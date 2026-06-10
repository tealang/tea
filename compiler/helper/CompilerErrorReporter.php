<?php

namespace Tea;

class CompilerErrorReporter
{
	public static function create_exception_issue(Unit $unit, Program $program, string $stage, Exception $e): array
	{
		return [
			'unit' => $unit->name,
			'stage' => $stage,
			'place' => self::get_program_place($unit, $program),
			'message' => $e->getMessage(),
			'trace' => self::collect_exception_trace($e),
		];
	}

	public static function create_warning_issues(Unit $unit, Program $program, string $stage, array $warnings): array
	{
		$issues = [];
		$place = self::get_program_place($unit, $program);
		foreach ($warnings as $warning) {
			$issues[] = [
				'unit' => $unit->name,
				'stage' => $stage,
				'place' => $place,
				'message' => $warning,
				'trace' => [],
			];
		}

		return $issues;
	}

	public static function format_check_issues(array $issues, string $title): string
	{
		$total_count = count($issues);
		$stage_counts = [];
		$unit_counts = [];
		$file_counts = [];
		$dedup_map = [];

		foreach ($issues as $error) {
			$stage = $error['stage'];
			$unit = $error['unit'];
			$place = $error['place'];
			$message = $error['message'];
			$parts = self::extract_check_error_parts($message);
			$display_unit = $parts['unit'] ?? $unit;
			$display_place = $place;
			if ($parts['location'] !== null and $parts['line'] !== null) {
				$display_place = "{$parts['location']}:{$parts['line']}";
			}

			$stage_counts[$stage] = ($stage_counts[$stage] ?? 0) + 1;
			$unit_counts[$display_unit] = true;
			$file_counts[$display_place] = true;

			$dedup_key = join('|', [$display_unit, $stage, $display_place, $message]);
			if (!isset($dedup_map[$dedup_key])) {
				$dedup_map[$dedup_key] = $error + [
					'display_unit' => $display_unit,
					'display_place' => $display_place,
					'repeats' => 1,
				];
			}
			else {
				$dedup_map[$dedup_key]['repeats']++;
			}
		}

		$unique_errors = array_values($dedup_map);
		usort($unique_errors, function(array $left, array $right) {
			$left_key = join('|', [$left['display_unit'], $left['stage'], $left['display_place'], $left['message']]);
			$right_key = join('|', [$right['display_unit'], $right['stage'], $right['display_place'], $right['message']]);
			return strcmp($left_key, $right_key);
		});

		ksort($stage_counts);

		$unit_count = count($unit_counts);
		$file_count = count($file_counts);
		$unique_count = count($unique_errors);
		$trace_limit = (int)(getenv('TEA_CHECK_TRACE_LIMIT') ?: 0);

		$message = "{$title}\n";
		$message .= "Summary: {$total_count} captured, {$unique_count} unique, {$unit_count} unit(s), {$file_count} file(s)\n\n";
		$message .= "Stage breakdown:\n";
		foreach ($stage_counts as $stage => $count) {
			$message .= "- {$stage}: {$count}\n";
		}

		$message .= "\nIssue details:\n";
		foreach ($unique_errors as $i => $error) {
			$idx = $i + 1;
			$parts = self::extract_check_error_parts($error['message']);
			$display_unit = $parts['unit'] ?? $error['unit'];

			$line = "{$idx}. [{$display_unit}] {$error['stage']}";
			if ($parts['location'] !== null and $parts['line'] !== null) {
				$line .= " @ {$parts['location']}:{$parts['line']}";
			}
			elseif ($error['place'] !== '') {
				$line .= " @ {$error['place']}";
			}
			$message .= $line . "\n";
			$message .= "   {$parts['summary']}\n";
			if ($parts['code'] !== null) {
				$message .= "   {$parts['code']}\n";
			}
			if ($parts['pointer'] !== null and trim($parts['pointer']) !== '') {
				$message .= "   {$parts['pointer']}\n";
			}
			if ($error['repeats'] > 1) {
				$message .= "   (repeated {$error['repeats']} times)\n";
			}
			if ($trace_limit > 0 and !empty($error['trace'])) {
				$message .= "   Trace (top {$trace_limit}):\n";
				$trace_items = array_slice($error['trace'], 0, $trace_limit);
				foreach ($trace_items as $trace_line) {
					$message .= "   - {$trace_line}\n";
				}
			}
			if ($i < $unique_count - 1) {
				$message .= "\n";
			}
		}

		return $message;
	}

	private static function get_program_place(Unit $unit, Program $program): string
	{
		return str_replace($unit->path, '', $program->file);
	}

	private static function extract_check_error_parts(string $message): array
	{
		$summary = trim(strtok($message, "\n"));
		if ($summary === '') {
			$summary = 'Unknown check error';
		}

		$location = null;
		$line = null;
		$code = null;
		$pointer = null;
		$unit = null;

		if (preg_match('/-----\R(.+?):([0-9]+)\R([^\r\n]*)\R([^\r\n]*)\R-----/u', $message, $matches) === 1) {
			$location = trim($matches[1]);
			$line = (int)$matches[2];
			$code = rtrim($matches[3]);
			$pointer = rtrim($matches[4]);

			if (str_contains($location, '::')) {
				[$unit, $location] = explode('::', $location, 2);
			}
		}

		return [
			'summary' => $summary,
			'unit' => $unit,
			'location' => $location,
			'line' => $line,
			'code' => $code,
			'pointer' => $pointer,
		];
	}

	private static function collect_exception_trace(Exception $e): array
	{
		$items = [];
		foreach ($e->getTrace() as $frame) {
			$file = isset($frame['file']) ? str_replace(TEA_BASE_PATH, '', (string)$frame['file']) : '[internal]';
			$line = isset($frame['line']) ? (int)$frame['line'] : 0;
			$line_part = $line > 0 ? ":{$line}" : '';
			$func = (string)($frame['function'] ?? 'unknown');
			$class = (string)($frame['class'] ?? '');
			$type = (string)($frame['type'] ?? '');
			$call = $class !== '' ? "{$class}{$type}{$func}" : $func;
			$call = str_replace('Tea\\', '', $call);
			$items[] = "{$file}{$line_part} {$call}()";
		}

		return $items;
	}
}
