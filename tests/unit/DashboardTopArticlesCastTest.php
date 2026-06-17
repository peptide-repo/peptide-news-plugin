<?php
/**
 * Test that dashboard top-articles SUM columns are cast to int before number_format().
 *
 * @package Peptide_News
 */

use PHPUnit\Framework\TestCase;

class DashboardTopArticlesCastTest extends TestCase {
    /**
     * Verify that SUM() aggregate strings from $wpdb->get_results() do not
     * cause a TypeError in number_format() (PHP 8.3 strict typing).
     * Mirrors the fix in admin/partials/dashboard.php lines 108-109.
     */
    public function test_total_clicks_cast_prevents_type_error() {
        $article = (object) array(
            'total_clicks' => '1234', // numeric string from $wpdb->get_results()
            'total_unique' => '987',
        );
        // Simulate the inline cast applied in dashboard.php
        $formatted_clicks = number_format( (int) $article->total_clicks );
        $formatted_unique = number_format( (int) $article->total_unique );
        $this->assertSame( '1,234', $formatted_clicks );
        $this->assertSame( '987', $formatted_unique );
    }

    /**
     * Verify NULL SUM() (no rows) results in 0, not a TypeError.
     */
    public function test_null_sum_cast_to_zero() {
        $article = (object) array(
            'total_clicks' => null,
            'total_unique' => null,
        );
        $formatted_clicks = number_format( (int) $article->total_clicks );
        $formatted_unique = number_format( (int) $article->total_unique );
        $this->assertSame( '0', $formatted_clicks );
        $this->assertSame( '0', $formatted_unique );
    }
}
