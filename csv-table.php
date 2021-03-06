<?php
/*
Copyright (C) 2012-2014 Sean Kelly

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

/**
 * @package csvtable
 * @version 2.0
 */
/*
Plugin Name: CSV Table
Plugin URI:
Description: Converts CSV formatted data to a table.
Author: Sean Kelly
Version: 2.0
Author URI:
License: GPL2
*/

namespace WGOM;

class CsvTable {
    const VERSION = 2.0;

    public function init() {
        // Add the shortcode for posts.
        \add_shortcode('sr', array('WGOM\CsvTable', 'sr_shortcode_cb'));
        \add_shortcode('table', array('WGOM\CsvTable', 'table_shortcode_cb'));
        // This is for comment text. The shortcode is safe for including in
        // comments, plus I think most people would want to use it there.
        \add_filter('comment_text', array('WGOM\CsvTable', 'shortcode_in_comment'));
        \add_action('wp_enqueue_scripts', array('WGOM\CsvTable', 'enqueue_scripts'));
    }

    public function enqueue_scripts() {
        \wp_enqueue_style('csv-table', plugins_url('sr-table.css', __FILE__));
        \wp_enqueue_script('csv-table',
                          \plugins_url('table-sort.js', __FILE__),
                          array('jquery'),
                          CsvTable::VERSION,
                          true
            );
    }

    public function shortcode_in_comment($content) {
        global $shortcode_tags;

        if (empty($shortcode_tags) || !is_array($shortcode_tags)) {
            return $content;
        }

        $_shortcode_tags = $shortcode_tags;
        $shortcode_tags = array();

        \add_shortcode('sr', array('WGOM\CsvTable', 'sr_shortcode_cb'));
        \add_shortcode('table', array('WGOM\CsvTable', 'table_shortcode_cb'));
        $new_content = \do_shortcode($content);

        $shortcode_tags = $_shortcode_tags;

        return $new_content;
    }

    public function sr_shortcode_cb($attrs, $content=null) {
        if (is_null($content)) {
            return '';
        }

        $thead = FALSE;
        $final_html = array();

        // Remove any '<br />' from the content.
        $content = preg_replace('|<br />$|m', '', $content);

        // Content is not null. Assume it's CSV data and try to make sense of it.
        $rows = str_getcsv($content, "\n");
        foreach ($rows as &$row) {
            $len = strlen($row);
            if ($len === 0) {
                continue;
            }

            $fields = str_getcsv($row);

            // Count the number of a-z character to try guess if it's a header.
            $count = preg_match_all('/[a-z]/i', $row, $matches);
            $is_header = (($count / $len) > 0.5);

            array_push($final_html, CsvTable::make_row($is_header, $fields, $thead));
        }

        return ('<table class="sports-reference nozebra">'
               . implode("", $final_html)
               . '</table>');
    }

    public function table_shortcode_cb($attrs, $content=null) {
        if (is_null($content)) {
            return '';
        }

        $delimiter = ',';
        if (array_key_exists($attrs, 'delimiter')) {
                $delimiter = $attrs['delimiter'];
        }

        $show_header = FALSE;
        if (array_key_exists($attrs, 'header')) {
            $header_opt = strtolower($attrs['header']);
            if ($header_opt === 'y' || $header_opt === 'yes' || $header_opt === 'true') {
                $show_header = TRUE;
            }
        }

        $thead = FALSE;
        $final_html = array();

        // Remove any '<br />' from the content.
        $content = preg_replace('|<br />$|m', '', $content);

        // Content is not null. Assume it's CSV data and try to make sense of
        // it.
        $row_index = 0;
        $rows = str_getcsv($content, "\n");
        foreach ($rows as &$row) {
            $len = strlen($row);
            if ($len === 0) {
                continue;
            }

            $fields = str_getcsv($row, $delimiter);

            array_push($final_html, CsvTable::make_row($show_header && $row_index === 0, $fields, $thead));
            $row_index++;
        }

        return ('<table>'
               . implode("", $final_html)
               . '</table>');
    }

    private function make_row($is_header, $columns, &$thead) {
        $row_classes = array();

        // Loop through the columns twice in order to count the number of cells
        // that have non-numeric characters in them.
        $alpha_cells = 0;
        $cells = count($columns);
        $data_len = 0;
        foreach ($columns as &$col) {
            $text = strip_tags($col);
            $count = preg_match_all('/[-+,.0-9]/i', $text, $matches);
            $len = strlen($text);
            $data_len += $len;
            if ($len > 0) {
                $alpha_cells += (($count / $len) <= 0.25);
            }
        }

        $row_classes[] = "alpha-cells-$alpha_cells";
        if ($alpha_cells >= (0.80 * $cells)) {
            $tag = 'th';
            if ($thead === TRUE) {
                $row_classes[] = 'thead';
            }
        }
        else {
            $tag = 'td';
        }

        foreach ($columns as &$col) {
            $col = CsvTable::wrap_column($col, $tag);
        }

        // If there is no data in the row, mark it as blank to
        // de-emphasize the row.
        if ($data_len === 0) {
            $row_classes[] = 'blank_row';
        }

        $row_class = '';
        if (count($row_classes) > 0) {
            $row_class = ' class="' . implode(' ', $row_classes) . '"';
        }

        $html = '<tr' . $row_class . '>' . implode('', $columns) . '</tr>';

        // Mark the first row as part of the thead section. This makes
        // sorting much easier, as it can handle just the body rows.
        if ($thead === FALSE) {
            $thead = TRUE;
            $html = '<thead>' . $html . '</thead>';
        }

        return $html;
    }

    private function wrap_column($column, $tag) {
        $align = '';
        $alpha_count = preg_match_all('/[a-z]/i', $column, $matches);
        $len = strlen($column);

        if ($tag === 'th') {
            $align = ' align="center"';
        }
        elseif (($len > 0) && (($alpha_count / $len) >= 0.5)) {
            $align = ' align="left"';
        }
        else {
            $align = ' align="right"';
        }

        return ('<' . $tag . $align . '>'
                . $column
                . '</' . $tag . '>');
    }
}

CsvTable::init();
