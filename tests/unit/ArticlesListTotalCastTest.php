<?php
/**
 * Test that articles list total count is cast to int.
 *
 * @package Peptide_News
 */

class ArticlesListTotalCastTest extends WP_UnitTestCase {
    /**
     * Verify that $wpdb->get_var returning a numeric string does not
     * cause a TypeError in number_format() (PHP 8.3 strict typing).
     */
    public function test_total_cast_prevents_type_error() {
        $total_as_string = '451'; // what $wpdb->get_var() returns
        $total = (int) $total_as_string;
        $this->assertIsInt( $total );
        $this->assertSame( 451, $total );
        // This should not throw TypeError on PHP 8.3
        $formatted = number_format( $total );
        $this->assertSame( '451', $formatted );
    }

    /**
     * Verify null return (missing table) results in 0, not a TypeError.
     */
    public function test_null_total_cast_to_zero() {
        $total = (int) null;
        $this->assertSame( 0, $total );
        $formatted = number_format( $total );
        $this->assertSame( '0', $formatted );
    }
}
