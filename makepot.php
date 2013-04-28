<?php

require_once dirname( __FILE__ ) . '/not-gettexted.php';
require_once dirname( __FILE__ ) . '/pot-ext-meta.php';
require_once dirname( __FILE__ ) . '/extract/extract.php';

if ( !defined( 'STDERR' ) ) {
	define( 'STDERR', fopen( 'php://stderr', 'w' ) );
}

class MakePOT {
	var $max_header_lines = 30;

	var $projects = array(
		'wp-plugin',
		'wp-theme',
	);

	var $rules = array(
		'_' =>                    array('string'),
		'__' =>                   array('string'),
		'_e' =>                   array('string'),
		'_c' =>                   array('string'),
		'_n' =>                   array('singular', 'plural'),
		'_n_noop' =>              array('singular', 'plural'),
		'_nc' =>                  array('singular', 'plural'),
		'__ngettext' =>           array('singular', 'plural'),
		'__ngettext_noop' =>      array('singular', 'plural'),
		'_x' =>                   array('string', 'context'),
		'_ex' =>                  array('string', 'context'),
		'_nx' =>                  array('singular', 'plural', null, 'context'),
		'_nx_noop' =>             array('singular', 'plural', 'context'),
		'_n_js' =>                array('singular', 'plural'),
		'_nx_js' =>               array('singular', 'plural', 'context'),
		'esc_attr__' =>           array('string'),
		'esc_html__' =>           array('string'),
		'esc_attr_e' =>           array('string'),
		'esc_html_e' =>           array('string'),
		'esc_attr_x' =>           array('string', 'context'),
		'esc_html_x' =>           array('string', 'context'),
		'comments_number_link' => array('string', 'singular', 'plural'),
	);

	var $ms_files = array( 'ms-.*', '.*/ms-.*', '.*/my-.*', 'wp-activate\.php', 'wp-signup\.php', 'wp-admin/network\.php', 'wp-admin/includes/ms\.php', 'wp-admin/network/.*\.php', 'wp-admin/includes/class-wp-ms.*' );

	var $temp_files = array();

	var $meta = array(
		'wp-plugin' => array(
			'description' => 'Translation of the WordPress plugin {name} {version} by {author}',
			'msgid-bugs-address' => 'http://wordpress.org/tag/{slug}',
			'copyright-holder' => '{author}',
			'package-name' => '{name}',
			'package-version' => '{version}',
		),
		'wp-theme' => array(
			'description' => 'Translation of the WordPress theme {name} {version} by {author}',
			'msgid-bugs-address' => 'http://wordpress.org/tags/{slug}',
			'copyright-holder' => '{author}',
			'package-name' => '{name}',
			'package-version' => '{version}',
			'comments' => 'Copyright (C) {year} {author}\nThis file is distributed under the same license as the {package-name} package.',
		),
	);

	function __construct($deprecated = true) {
		$this->extractor = new StringExtractor( $this->rules );
	}

	function __destruct() {
		foreach ( $this->temp_files as $temp_file )
			unlink( $temp_file );
	}

	function tempnam( $file ) {
		$tempnam = tempnam( sys_get_temp_dir(), $file );
		$this->temp_files[] = $tempnam;
		return $tempnam;
	}

	function realpath_missing($path) {
		return realpath(dirname($path)).DIRECTORY_SEPARATOR.basename($path);
	}

	function xgettext($project, $dir, $output_file, $placeholders = array(), $excludes = array(), $includes = array()) {
		$meta = array_merge( $this->meta['default'], $this->meta[$project] );
		$placeholders = array_merge( $meta, $placeholders );
		$meta['output'] = $this->realpath_missing( $output_file );
		$placeholders['year'] = date( 'Y' );
		$placeholder_keys = array_map( create_function( '$x', 'return "{".$x."}";' ), array_keys( $placeholders ) );
		$placeholder_values = array_values( $placeholders );
		foreach($meta as $key => $value) {
			$meta[$key] = str_replace($placeholder_keys, $placeholder_values, $value);
		}

		$originals = $this->extractor->extract_from_directory( $dir, $excludes, $includes );
		$pot = new PO;
		$pot->entries = $originals->entries;

		$pot->set_header( 'Project-Id-Version', $meta['package-name'].' '.$meta['package-version'] );
		$pot->set_header( 'Report-Msgid-Bugs-To', $meta['msgid-bugs-address'] );
		$pot->set_header( 'POT-Creation-Date', gmdate( 'Y-m-d H:i:s+00:00' ) );
		$pot->set_header( 'MIME-Version', '1.0' );
		$pot->set_header( 'Content-Type', 'text/plain; charset=UTF-8' );
		$pot->set_header( 'Content-Transfer-Encoding', '8bit' );
		$pot->set_header( 'PO-Revision-Date', date( 'Y') . '-MO-DA HO:MI+ZONE' );
		$pot->set_header( 'Last-Translator', 'FULL NAME <EMAIL@ADDRESS>' );
		$pot->set_header( 'Language-Team', 'LANGUAGE <LL@li.org>' );
		$pot->set_comment_before_headers( $meta['comments'] );
		$pot->export_to_file( $output_file );
		return true;
	}

	function get_first_lines($filename, $lines = 30) {
		$extf = fopen($filename, 'r');
		if (!$extf) return false;
		$first_lines = '';
		foreach(range(1, $lines) as $x) {
			$line = fgets($extf);
			if (feof($extf)) break;
			if (false === $line) {
				return false;
			}
			$first_lines .= $line;
		}
		return $first_lines;
	}


	function get_addon_header($header, &$source) {
		if (preg_match('|'.$header.':(.*)$|mi', $source, $matches))
			return trim($matches[1]);
		else
			return false;
	}

	function generic($dir, $output) {
		$output = is_null($output)? "generic.pot" : $output;
		return $this->xgettext('generic', $dir, $output, array());
	}

	function guess_plugin_slug($dir) {
		if ('trunk' == basename($dir)) {
			$slug = basename(dirname($dir));
		} elseif (in_array(basename(dirname($dir)), array('branches', 'tags'))) {
			$slug = basename(dirname(dirname($dir)));
		} else {
			$slug = basename($dir);
		}
		return $slug;
	}

	function wp_plugin($dir, $output, $slug = null) {
		$placeholders = array();
		// guess plugin slug
		if (is_null($slug)) {
			$slug = $this->guess_plugin_slug($dir);
		}
		$main_file = $dir.'/'.$slug.'.php';
		$source = $this->get_first_lines($main_file, $this->max_header_lines);

		$placeholders['version'] = $this->get_addon_header('Version', $source);
		$placeholders['author'] = $this->get_addon_header('Author', $source);
		$placeholders['name'] = $this->get_addon_header('Plugin Name', $source);
		$placeholders['slug'] = $slug;

		$output = is_null($output)? "$slug.pot" : $output;
		$res = $this->xgettext('wp-plugin', $dir, $output, $placeholders);
		if (!$res) return false;
		$potextmeta = new PotExtMeta;
		$res = $potextmeta->append($main_file, $output);
		/* Adding non-gettexted strings can repeat some phrases */
		$output_shell = escapeshellarg($output);
		system("msguniq $output_shell -o $output_shell");
		return $res;
	}

	function wp_theme($dir, $output, $slug = null) {
		$placeholders = array();
		// guess plugin slug
		if (is_null($slug)) {
			$slug = $this->guess_plugin_slug($dir);
		}
		$main_file = $dir.'/style.css';
		$source = $this->get_first_lines($main_file, $this->max_header_lines);

		$placeholders['version'] = $this->get_addon_header('Version', $source);
		$placeholders['author'] = $this->get_addon_header('Author', $source);
		$placeholders['name'] = $this->get_addon_header('Theme Name', $source);
		$placeholders['slug'] = $slug;

		$license = $this->get_addon_header( 'License', $source );
		if ( $license )
			$this->meta['wp-theme']['comments'] = "Copyright (C) {year} {author}\nThis file is distributed under the {$license}.";
		else
			$this->meta['wp-theme']['comments'] = "Copyright (C) {year} {author}\nThis file is distributed under the same license as the {package-name} package.";

		$output = is_null($output)? "$slug.pot" : $output;
		$res = $this->xgettext('wp-theme', $dir, $output, $placeholders);
		if (! $res )
			return false;
		$potextmeta = new PotExtMeta;
		$res = $potextmeta->append( $main_file, $output, array( 'Theme Name', 'Theme URI', 'Description', 'Author', 'Author URI' ) );
		if ( ! $res )
			return false;
		// If we're dealing with a pre-3.4 default theme, don't extract page templates before 3.4.
		$extract_templates = ! in_array( $slug, array( 'twentyten', 'twentyeleven', 'default', 'classic' ) );
		if ( ! $extract_templates ) {
			$wp_dir = dirname( dirname( dirname( $dir ) ) );
			$extract_templates = file_exists( "$wp_dir/wp-admin/user/about.php" ) || ! file_exists( "$wp_dir/wp-load.php" );
		}
		if ( $extract_templates ) {
			$res = $potextmeta->append( $dir, $output, array( 'Template Name' ) );
			if ( ! $res )
				return false;
			$files = scandir( $dir );
			foreach ( $files as $file ) {
				if ( '.' == $file[0] || 'CVS' == $file )
					continue;
				if ( is_dir( $dir . '/' . $file ) ) {
					$res = $potextmeta->append( $dir . '/' . $file, $output, array( 'Template Name' ) );
					if ( ! $res )
						return false;
				}
			}
		}
		/* Adding non-gettexted strings can repeat some phrases */
		$output_shell = escapeshellarg($output);
		system("msguniq $output_shell -o $output_shell");
		return $res;
	}

	function is_ms_file( $file_name ) {
		$is_ms_file = false;
		$prefix = substr( $file_name, 0, 2 ) === './'? '\./' : '';
		foreach( $this->ms_files as $ms_file )
			if ( preg_match( '|^'.$prefix.$ms_file.'$|', $file_name ) ) {
				$is_ms_file = true;
				break;
			}
		return $is_ms_file;
	}

	function is_not_ms_file( $file_name ) {
		return !$this->is_ms_file( $file_name );
	}
}

// run the CLI only if the file
// wasn't included
$included_files = get_included_files();
if ($included_files[0] == __FILE__) {
	$makepot = new MakePOT;
	if ((3 == count($argv) || 4 == count($argv)) && in_array($method = str_replace('-', '_', $argv[1]), get_class_methods($makepot))) {
		$res = call_user_func(array(&$makepot, $method), realpath($argv[2]), isset($argv[3])? $argv[3] : null);
		if (false === $res) {
			fwrite(STDERR, "Couldn't generate POT file!\n");
		}
	} else {
		$usage  = "Usage: php makepot.php PROJECT DIRECTORY [OUTPUT]\n\n";
		$usage .= "Generate POT file from the files in DIRECTORY [OUTPUT]\n";
		$usage .= "Available projects: ".implode(', ', $makepot->projects)."\n";
		fwrite(STDERR, $usage);
		exit(1);
	}
}