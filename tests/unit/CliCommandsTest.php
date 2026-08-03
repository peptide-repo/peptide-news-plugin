<?php
/**
 * Unit tests for Peptide_News_CLI commands.
 *
 * @since 2.6.0
 */

use PHPUnit\Framework\TestCase;

class CliCommandsTest extends TestCase {

	public function test_cli_class_exists_and_extends_command(): void {
		$this->assertTrue( class_exists( 'Peptide_News_CLI' ) );
		$cli = new Peptide_News_CLI();
		$this->assertInstanceOf( 'WP_CLI_Command', $cli );
	}

	public function test_cli_methods_exist(): void {
		$cli = new Peptide_News_CLI();
		$this->assertTrue( method_exists( $cli, 'fetch' ) );
		$this->assertTrue( method_exists( $cli, 'process_llm' ) );
		$this->assertTrue( method_exists( $cli, 'backfill_sources' ) );
	}
}
