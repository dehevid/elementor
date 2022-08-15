<?php
namespace Elementor\Tests\Phpunit\Elementor\Core\App\ImportExport;

use Elementor\Core\App\Modules\ImportExport\Runners\Elementor_Content;
use Elementor\Core\App\Modules\ImportExport\Runners\Plugins;
use Elementor\Core\App\Modules\ImportExport\Runners\Site_Settings;
use Elementor\Core\App\Modules\ImportExport\Runners\Taxonomies;
use Elementor\Core\App\Modules\ImportExport\Runners\Templates;
use Elementor\Core\App\Modules\ImportExport\Runners\Wp_Content;
use Elementor\Core\App\Modules\ImportExport\Processes\Import;
use Elementor\Core\App\Modules\ImportExport\Utils as ImportExportUtils;
use Elementor\Core\Settings\Page\Manager as PageManager;
use Elementor\Core\Utils\Plugins_Manager;
use Elementor\Plugin;
use ElementorEditorTesting\Elementor_Test_Base;

class Test_Import extends Elementor_Test_Base {

	// Test the import all process, which include all the kit content:
	// The plugins, site-settings, taxonomies ( for Elementor and WP including CPT taxonomy - tests_tax ),
	// Elementor content, WP content ( including CPTs - tests, sectests ).
	public function test_run__import_all() {
		// Arrange
		register_post_type( 'tests' );
		register_post_type( 'sectests' );
		register_taxonomy( 'tests_tax', [ 'tests' ], [] );

		$plugins_manager_mock = $this->getMockBuilder( Plugins_Manager::class )
			->setMethods( [ 'install', 'activate' ] )
			->getMock();

		$plugins_manager_mock->expects( $this->once() )
			->method( 'install' )
			->willReturn( [
				'succeeded' => [
					'elementor/elementor.php',
					'elementor-pro/elementor-pro.php',
				]
			] );

		$plugins_manager_mock->expects( $this->once() )
			->method( 'activate' )
			->willReturn( [
				'succeeded' => [
					'elementor/elementor.php',
					'elementor-pro/elementor-pro.php',
				]
			] );

		$zip_path = __DIR__ . '/mock/sample-kit.zip';
		$extraction_result = Plugin::$instance->uploads_manager->extract_and_validate_zip( $zip_path, [ 'json', 'xml' ] );
		$session = $extraction_result['extraction_directory'];
		$import = new Import( $session );
		$manifest = $import->get_manifest();

		$import->register( new Plugins( $plugins_manager_mock ) );
		$import->register( new Site_Settings() );
		$import->register( new Taxonomies() );
		$import->register( new Templates() );
		$import->register( new Elementor_Content() );
		$import->register( new Wp_Content() );

		// Act
		$result = $import->run();

		// Assert
		$this->assertEquals( [ 'Elementor', 'Elementor Pro' ], $result['plugins']);
		$this->assertTrue( $result['site-settings'] );
		$this->assert_valid_taxonomies( $result );
		$this->assertCount( 1, $result['content']['post']['succeed'] );
		$this->assertCount( 1, $result['content']['page']['succeed'] );
		$this->assertCount( 1, $result['wp-content']['post']['succeed'] );
		$this->assertCount( 1, $result['wp-content']['page']['succeed'] );
		$this->assertCount( 1, $result['wp-content']['tests']['succeed'] );
		$this->assertCount( 9, $result['wp-content']['nav_menu_item']['succeed'] );

		$this->assert_valid_terms_with_elementor_content( $result, $manifest );
		$this->assert_valid_terms_with_wp_content( $result );

		// Cleanups
		unregister_taxonomy_for_object_type( 'tests_tax', 'tests' );
		unregister_post_type( 'tests' );
		unregister_post_type( 'sectests' );
	}

	public function test_run__fail_when_not_registered_runners() {
		// Expect
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Please specify import runners.' );

		// Arrange
		$import = new Import( __DIR__ . '/mock/sample-kit.zip', [] );

		// Act
		$import->run();
	}

	public function test_run__import_plugins_all_plugins_by_default() {
		// Arrange
		$plugins_manager_mock = $this->getMockBuilder( Plugins_Manager::class )
			->setMethods( [ 'install', 'activate' ] )
			->getMock();

		$plugins_manager_mock->expects( $this->once() )
			->method( 'install' )
			->willReturn( [
				'succeeded' => [
					'elementor/elementor.php',
					'elementor-pro/elementor-pro.php',
				]
			] );

		$plugins_manager_mock->expects( $this->once() )
			->method( 'activate' )
			->willReturn( [
				'succeeded' => [
					'elementor/elementor.php',
					'elementor-pro/elementor-pro.php',
				]
			] );

		$import_settings = [
			'include' => [ 'plugins' ],
		];

		$import = new Import( __DIR__ . '/mock/sample-kit.zip', $import_settings );
		$import->register( new Plugins( $plugins_manager_mock ) );

		// Act
		$result = $import->run();

		// Assert
		$this->assertCount( 1, $result );
		$this->assertEquals( [ 'Elementor', 'Elementor Pro' ], $result['plugins'] );
	}

	public function test_run__import_plugins_selected_plugin() {
		// Arrange
		$plugins_manager_mock = $this->getMockBuilder( Plugins_Manager::class )
			->setMethods( [ 'install', 'activate' ] )
			->getMock();

		$plugins_manager_mock->expects( $this->once() )
			->method( 'install' )
			->willReturn( [ 'succeeded' => [
				'elementor/elementor.php',
			] ] );

		$plugins_manager_mock->expects( $this->once() )
			->method( 'activate' )
			->willReturn( [ 'succeeded' => [
				'elementor/elementor.php',
			] ] );

		$import_settings = [
			'include' => [ 'plugins' ],
			'plugins' => [
				[
					'name' => 'Elementor',
					'plugin' => 'elementor/elementor',
					'pluginUri' => 'https://elementor.com/?utm_source=wp-plugins&#038;utm_campaign=plugin-uri&#038;utm_medium=wp-dash',
		            'version' => '3.6.5',
				],
			],
		];

		$import = new Import( __DIR__ . '/mock/sample-kit.zip', $import_settings );
		$import->register( new Plugins( $plugins_manager_mock ) );

		// Act
		$result = $import->run();

		// Assert
		$this->assertCount( 1, $result );
		$this->assertEquals( [ 'Elementor', ], $result['plugins'] );
	}

	public function test_run__import_site_settings() {
		// Arrange
		$this->act_as_admin();

		$import_settings = [
			'include' => [ 'settings' ],
		];
		$import = new Import( __DIR__ . '/mock/sample-kit.zip', $import_settings );
		$import->register( new Site_Settings() );

		$extracted_directory_path = $import->get_extracted_directory_path();
		$site_settings = ImportExportUtils::read_json_file( $extracted_directory_path . 'site-settings' );
		$expected_custom_colors = $site_settings['settings']['custom_colors'];
		$expected_custom_typography =  $site_settings['settings']['custom_typography'];

		// Act
		$result = $import->run();

		// Assert
		$this->assertCount( 1, $result );
		$this->assertTrue( $result['site-settings'] );

		$new_active_kit = Plugin::$instance->kits_manager->get_active_kit();
		$this->assertEquals( 'Imported Kit', $new_active_kit->get_post()->post_title );

		$new_settings = $new_active_kit->get_meta( PageManager::META_KEY );
		$this->assertEquals( $expected_custom_colors, $new_settings['custom_colors'] );
		$this->assertEquals( $expected_custom_typography, $new_settings['custom_typography'] );
	}

	public function test_run__import_templates_will_be_empty_without_the_pro() {
		// Arrange
		$import_settings = [
			'include' => [ 'templates' ],
		];
		$import = new Import( __DIR__ . '/mock/sample-kit.zip', $import_settings );
		$import->register( new Templates() );

		// Act
		$result = $import->run();

		// Assert
		$this->assertEmpty( $result );
	}

	public function test_run__import_taxonomies_without_register_custom_taxonomies() {
		// Arrange
		$import_settings = [
			'include' => [ 'content' ],
		];
		$import = new Import( __DIR__ . '/mock/sample-kit.zip', $import_settings );
		$import->register( new Taxonomies() );

		// Act
		$result = $import->run();

		// Assert
		$this->assertCount( 1, $result );
		$this->assert_valid_taxonomies( $result );
	}

	public function test_run__import_taxonomies_with_register_custom_taxonomies() {
		// Arrange
		register_post_type( 'tests' );
		register_taxonomy( 'tests_tax', [ 'tests' ], [] );

		$import_settings = [
			'include' => [ 'content' ],
		];
		$import = new Import( __DIR__ . '/mock/sample-kit.zip', $import_settings );
		$import->register( new Taxonomies() );

		// Act
		$result = $import->run();

		// Assert
		$this->assertCount( 1, $result );
		$this->assert_valid_taxonomies( $result );

		// Cleanups
		unregister_taxonomy_for_object_type( 'tests_tax', 'tests' );
		unregister_post_type( 'tests' );
	}

	public function test_run__import_elementor_content_only() {
		// Arrange
		$this->act_as_admin();

		$import_settings = [
			'include' => [ 'content' ],
			'selectedCustomPostTypes' => [],
		];

		$zip_path = __DIR__ . '/mock/sample-kit.zip';
		$import = new Import( $zip_path, $import_settings );
		$import->register( new Elementor_Content() );
		$manifest = $import->get_manifest();

		// Act
		$result = $import->run();

		// Assert
		$this->assertCount( 1, $result );
		$this->assertCount( 1, $result['content']['post']['succeed'] );
		$this->assertCount( 1, $result['content']['page']['succeed'] );

		$this->assert_valid_elementor_content( $result, $manifest, $zip_path );
	}

	public function test_run__import_wp_content_with_one_cpt_register_and_one_not() {
		// Arrange
		register_post_type( 'tests' );

		$import_settings = [
			'include' => [ 'content' ],
		];

		$import = new Import( __DIR__ . '/mock/sample-kit.zip', $import_settings );
		$import->register( new Wp_Content() );

		// Act
		$result = $import->run();

		$this->assertCount( 1, $result );
		$this->assertCount( 1, $result['wp-content']['post']['succeed'] );
		$this->assertCount( 1, $result['wp-content']['page']['succeed'] );
		$this->assertCount( 1, $result['wp-content']['tests']['succeed'] );
		$this->assertCount( 4, $result['wp-content']['nav_menu_item']['succeed'] );
		$this->assertFalse( isset( $result['wp-content']['sectests'] ) );

		unregister_post_type( 'tests' );
	}

	public function test_run__import_content_with_one_cpt_selected_and_one_not() {
		// Arrange
		register_post_type( 'tests' );
		register_post_type( 'sectests' );

		$import_settings = [
			'include' => [ 'content' ],
			'selectedCustomPostTypes' => [ 'tests' ],
		];

		$import = new Import( __DIR__ . '/mock/sample-kit.zip', $import_settings );
		$import->register( new Elementor_Content() );
		$import->register( new Wp_Content() );

		// Act
		$result = $import->run();

		// Assert
		$this->assertCount( 1, $result['content']['post']['succeed'] );
		$this->assertCount( 1, $result['content']['page']['succeed'] );
		$this->assertCount( 1, $result['wp-content']['post']['succeed'] );
		$this->assertCount( 1, $result['wp-content']['page']['succeed'] );
		$this->assertCount( 1, $result['wp-content']['tests']['succeed'] );
		$this->assertCount( 6, $result['wp-content']['nav_menu_item']['succeed'] );
		$this->assertFalse( isset( $result['wp-content']['sectests'] ) );

		// Cleanup
		unregister_post_type( 'tests' );
		unregister_post_type( 'sectests' );
	}

	public function test_run__envato_kit() {
		// Arrange
		$import = new Import( __DIR__ . '/mock/envato-kit.zip', [] );
		$import->register_default_runners();

		// Act
		$result = $import->run();

		// Assert
		$this->assertTrue( isset( $result['site-settings'] ) );
		$this->assertFalse( isset( $result['templates'] ) );
	}

	// Test the set default function. In this test we are also testing the import process by session ID.
	public function test_set_default__import_by_session() {
		// Arrange
		$zip_path = __DIR__ . '/mock/sample-kit.zip';
		$extraction_result = Plugin::$instance->uploads_manager->extract_and_validate_zip( $zip_path, [ 'json', 'xml' ] );
		$session = $extraction_result['extraction_directory'];

		// Act
		$import = new Import( $session );

		// Assert
		$manifest = $import->get_manifest();
		$expected_settings_include = [ 'templates', 'plugins', 'content', 'settings' ];
		$expected_settings_referrer = 'local';
		$expected_settings_selected_custom_post_types = [ 'tests', 'sectests' ];
		$expected_settings_selected_override_conditions = [];
		$expected_settings_selected_plugins = $manifest['plugins'];

		$settings_include = $import->get_settings_include();
		$settings_referrer = $import->get_settings_referrer();
		$settings_selected_custom_post_types = $import->get_settings_selected_custom_post_types();
		$settings_selected_override_conditions = $import->get_settings_selected_override_conditions();
		$settings_selected_plugins = $import->get_settings_selected_plugins();

		$this->assertEquals( $expected_settings_include, $settings_include );
		$this->assertEquals( $expected_settings_referrer, $settings_referrer );
		$this->assertEqualSets( $expected_settings_selected_custom_post_types, $settings_selected_custom_post_types );
		$this->assertEquals( $expected_settings_selected_override_conditions, $settings_selected_override_conditions );
		$this->assertEquals( $expected_settings_selected_plugins, $settings_selected_plugins );
	}

	public function test_construct__importing_a_not_existing_session_throws_an_error() {
		// Arrange
		$elementor_tmp_directory = Plugin::$instance->uploads_manager->get_temp_dir();

		// Expect
		$this->expectExceptionMessage( 'session-does-not-exits-error' );

		// Act
		$import = new Import( $elementor_tmp_directory . 'session-not-exits', [] );
	}

	// Test if the kit-library adapter is running and performing correctly.
	public function test_get_manifest__kit_library() {
		$import_settings = [
			'referrer' => 'kit-library',
		];

		$import = new Import( __DIR__ . '/mock/kit-library-manifest-only.zip', $import_settings );
		$manifest = $import->get_manifest();

		foreach ( $manifest['content']['page'] as $page ) {
			$this->assertFalse( $page['thumbnail'] );
		}

		foreach ( $manifest['templates'] as $template ) {
			$this->assertFalse( $template['thumbnail'] );
		}
	}

	private function assert_valid_taxonomies( $result ) {
		$this->assertNotEmpty( $result['taxonomies'] );

		foreach ( $result['taxonomies'] as $post_type_taxonomies ) {
			foreach ( $post_type_taxonomies as $taxonomy_key => $imported_terms ) {
				foreach ( $imported_terms as $imported_term ) {
					$imported_term_id = $imported_term['new_id'];
					$imported_term_slug = $imported_term['new_slug'];

					$term = get_term( $imported_term_id );
					$this->assertTrue( $term->taxonomy === $taxonomy_key );
					$this->assertTrue( $term->slug === $imported_term_slug );
				}
			}
		}
	}

	private function recursive_unset( &$elements, $unwanted_key ) {
		foreach ( $elements as &$element ) {
			unset( $element[ $unwanted_key ] );
			$this->recursive_unset( $element['elements'], $unwanted_key );
		}
	}

	// Assertions for elementor content by testing if the post containing the "JSON" content.
	// The "JSON" is located in the content folder inside the kit.
	private function assert_valid_elementor_content( $result, $manifest, $zip_path) {
		$import_process_tmp_dir = Plugin::$instance->uploads_manager->extract_and_validate_zip( $zip_path )['extraction_directory'];
		
		foreach ( $manifest['content'] as $elementor_post_type => $elementor_posts ) {
			foreach ( $elementor_posts as $post_id => $post_settings ) {
				$expected_post_data = ImportExportUtils::read_json_file( $import_process_tmp_dir . '/content/' . $elementor_post_type . '/' . $post_id );
				$expected_post_content = $expected_post_data['content'];

				$imported_post_id = $result['content'][ $elementor_post_type ]['succeed'][ $post_id ];
				$new_post = Plugin::$instance->documents->get( $imported_post_id, false );
				$post_content = $new_post->get_json_meta('_elementor_data');

				$this->recursive_unset( $post_content, 'isInner' );
				$this->recursive_unset( $expected_post_content, 'isInner' );

				$this->assertEquals( $expected_post_content, $post_content );
			}
		}

		// Cleanup
		Plugin::$instance->uploads_manager->remove_file_or_dir( $import_process_tmp_dir );
	}

	// Assertions for imported taxonomies and imported Elementor content by testing the terms of every Elementor post.
	private function assert_valid_terms_with_elementor_content( $result, $manifest ) {
		foreach ( $manifest['content'] as $elementor_post_type => $elementor_posts ) {
			foreach ( $elementor_posts as $post_id => $post_settings ) {
				$expected_post_terms = $manifest['content'][ $elementor_post_type ][ $post_id ]['terms'];
				$imported_post_id = $result['content'][ $elementor_post_type ]['succeed'][ $post_id ];

				foreach ( $expected_post_terms as $term ) {
					$post_terms = get_the_terms( $imported_post_id, $term['taxonomy'] );
					$this->assertEquals( $term['slug'], $post_terms[0]->slug );
				}
			}
		}
	}

	// Assertions for imported taxonomies and imported WP content by testing the terms of every WP post.
	private function assert_valid_terms_with_wp_content( $result ) {
		foreach ( $result['wp-content'] as $wp_post_type => $wp_posts ) {
			foreach ( $wp_posts['succeed'] as $new_post_id ) {
				if ( ! isset( $result['taxonomies'][ $wp_post_type ] ) ) {
					continue;
				}

				foreach ( $result['taxonomies'][ $wp_post_type ] as $taxonomy => $terms ) {
					$post_terms = get_the_terms( $new_post_id, $taxonomy );
					$this->assertNotEmpty( $post_terms );
				}
			}
		}
	}
}
