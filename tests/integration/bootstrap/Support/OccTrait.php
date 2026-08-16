<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\N8nSync\Tests\Integration\Support;

use PHPUnit\Framework\Assert;

/**
 * occ transport: run the admin CLI the way an operator (or our own occ commands)
 * would. Composed into {@see \OCA\N8nSync\Tests\Integration\FeatureContext}.
 *
 * Reads/writes the shared `$occ` / `$lastExit` / `$lastOutput` state declared on
 * the context. `occStdin` is the stdin variant `set-api-key` needs to keep the
 * key off the process list.
 */
trait OccTrait {
	/**
	 * Run an occ command. $args is appended to the occ prefix verbatim.
	 *
	 * @return array{exit:int, output:string}
	 */
	private function occ(string $args): array {
		$cmd = $this->occ . ' ' . $args . ' 2>&1';
		$output = [];
		$exit = 0;
		exec($cmd, $output, $exit);
		$this->lastExit = $exit;
		$this->lastOutput = implode("\n", $output);
		return ['exit' => $exit, 'output' => $this->lastOutput];
	}

	/**
	 * `occ` with extra environment — the only way to hand a password to `user:add`
	 * without a TTY (`--password-from-env` reads `OC_PASS`). Values are escaped and
	 * prefixed to the command rather than exported, so nothing leaks into later calls.
	 *
	 * @param array<string,string> $env
	 * @return array{exit:int, output:string}
	 */
	private function occEnv(string $args, array $env): array {
		$prefix = '';
		foreach ($env as $key => $value) {
			$prefix .= $key . '=' . escapeshellarg($value) . ' ';
		}
		$cmd = $prefix . $this->occ . ' ' . $args . ' 2>&1';
		$output = [];
		$exit = 0;
		exec($cmd, $output, $exit);
		$this->lastExit = $exit;
		$this->lastOutput = implode("\n", $output);
		return ['exit' => $exit, 'output' => $this->lastOutput];
	}

	/**
	 * Run an occ command with data piped on stdin (for `set-api-key`, which reads
	 * the key from stdin to keep it off the process list).
	 *
	 * @return array{exit:int, output:string}
	 */
	private function occStdin(string $cmd, string $stdin): array {
		$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$proc = proc_open($cmd, $descriptors, $pipes);
		Assert::assertIsResource($proc, "could not start: $cmd");
		fwrite($pipes[0], $stdin);
		fclose($pipes[0]);
		$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit = proc_close($proc);
		$this->lastExit = $exit;
		$this->lastOutput = $out;
		return ['exit' => $exit, 'output' => $out];
	}
}
