<?php
/**
 * ViteAsset 測試：build 產物檔名帶 content hash，PHP 由 manifest.json 取得實際檔名
 *
 * 背景（齒輪按鈕沒反應）：主機對 js/dist/* 回 `Cache-Control: immutable, max-age=31536000` 且忽略 ?ver=，
 * 固定檔名 index.js 更新外掛後瀏覽器仍跑舊 bundle；改為 hash 檔名後內容一變網址就變。
 */

declare( strict_types=1 );

namespace Tests\Integration\Shared;

use J7\PowerCheckout\Domains\Settings\Services\SettingTabService;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Utils\ViteAsset;
use Tests\Integration\TestCase;

/**
 * @group smoke
 * @group happy
 * @group edge
 */
final class ViteAssetTest extends TestCase {

	/** @var string 測試用外掛根目錄 */
	private string $root = '';

	public function set_up(): void {
		parent::set_up();
		$this->root = \sys_get_temp_dir() . '/pc-vite-asset-' . \wp_generate_password( 8, false );
		\wp_mkdir_p( $this->root . '/js/dist' );
		\wp_mkdir_p( $this->root . '/inc/assets/dist/blocks' );
		ViteAsset::reset( $this->root );
	}

	public function tear_down(): void {
		ViteAsset::reset();
		$this->remove_dir( $this->root );
		parent::tear_down();
	}

	/** @param array<string, mixed> $manifest */
	private function write_manifest( string $dist_dir, array $manifest ): void {
		\file_put_contents( $this->root . '/' . $dist_dir . '/manifest.json', (string) \wp_json_encode( $manifest ) );
		ViteAsset::reset( $this->root );
	}

	private function remove_dir( string $dir ): void {
		if ( '' === $dir || !\is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			/** @var \SplFileInfo $item */
			$item->isDir() ? \rmdir( $item->getPathname() ) : \unlink( $item->getPathname() );
		}
		\rmdir( $dir );
	}

	/** @group happy */
	public function test_app_urls_come_from_manifest(): void {
		$this->write_manifest(
			'js/dist',
			[
				'js/src/index.ts' => [
					'file'    => 'index-AbC123.js',
					'isEntry' => true,
					'css'     => [ 'index-XyZ789.css' ],
				],
			]
		);

		$this->assertSame( Plugin::$url . '/js/dist/index-AbC123.js', ViteAsset::app_script_url() );
		$this->assertSame( Plugin::$url . '/js/dist/index-XyZ789.css', ViteAsset::app_style_url() );
		$this->assertNull( ViteAsset::app_version(), 'hash 檔名不加 ?ver=（避免 entry 被當成兩個 module 執行兩次）' );
	}

	/** @group happy */
	public function test_new_build_changes_urls(): void {
		$this->write_manifest(
			'js/dist',
			[
				'js/src/index.ts' => [
					'file' => 'index-OLD.js',
					'css'  => [ 'index-OLD.css' ],
				],
			]
			);
		$old = ViteAsset::app_script_url();

		$this->write_manifest(
			'js/dist',
			[
				'js/src/index.ts' => [
					'file' => 'index-NEW.js',
					'css'  => [ 'index-NEW.css' ],
				],
			]
			);
		$new = ViteAsset::app_script_url();

		$this->assertNotSame( $old, $new, '重新 build 後 entry 網址必須改變（不靠 ?ver=）' );
	}

	/** @group happy */
	public function test_block_url_and_exists_from_manifest(): void {
		$this->write_manifest(
			'inc/assets/dist/blocks',
			[
				'inc/assets/blocks/pc_logistics.tsx' => [
					'file'    => 'pc_logistics-D_LRp6nr.js',
					'isEntry' => true,
				],
			]
		);

		$this->assertSame( Plugin::$url . '/inc/assets/dist/blocks/pc_logistics-D_LRp6nr.js', ViteAsset::block_script_url( 'pc_logistics' ) );
		$this->assertFalse( ViteAsset::block_script_exists( 'pc_logistics' ), 'manifest 有但檔案不在磁碟 → false' );

		\file_put_contents( $this->root . '/inc/assets/dist/blocks/pc_logistics-D_LRp6nr.js', '' );
		$this->assertTrue( ViteAsset::block_script_exists( 'pc_logistics' ) );
	}

	/** @group edge */
	public function test_missing_manifest_falls_back_to_legacy_names(): void {
		$this->assertSame( Plugin::$url . '/js/dist/index.js', ViteAsset::app_script_url() );
		$this->assertSame( Plugin::$url . '/js/dist/index.css', ViteAsset::app_style_url() );
		$this->assertSame( Plugin::$url . '/inc/assets/dist/blocks/pc_invoice.js', ViteAsset::block_script_url( 'pc_invoice' ) );
		$this->assertSame( Plugin::$version, ViteAsset::app_version(), '舊固定檔名仍以外掛版本號 cache-bust' );
		$this->assertSame( Plugin::$version, ViteAsset::block_version( 'pc_invoice' ) );
	}

	/** @group edge */
	public function test_corrupt_manifest_falls_back(): void {
		\file_put_contents( $this->root . '/js/dist/manifest.json', '{not json' );
		ViteAsset::reset( $this->root );

		$this->assertSame( Plugin::$url . '/js/dist/index.js', ViteAsset::app_script_url() );
	}

	/** @group edge */
	public function test_entry_missing_from_manifest_falls_back(): void {
		$this->write_manifest( 'inc/assets/dist/blocks', [ 'inc/assets/blocks/other.tsx' => [ 'file' => 'other-1.js' ] ] );

		$this->assertSame( Plugin::$url . '/inc/assets/dist/blocks/linepay.js', ViteAsset::block_script_url( 'linepay' ) );
	}

	/** @group edge */
	public function test_entry_without_css_returns_empty_list(): void {
		$this->write_manifest( 'js/dist', [ 'js/src/index.ts' => [ 'file' => 'index-1.js' ] ] );

		$this->assertSame( [], ViteAsset::entry_css( ViteAsset::APP_DIST, ViteAsset::APP_ENTRY, [ 'index.css' ] ) );
		$this->assertSame( Plugin::$url . '/js/dist/index.css', ViteAsset::app_style_url(), '無 CSS 時退回舊檔名（不產生空網址）' );
	}

	/** @group smoke */
	public function test_setting_tab_registers_hashed_script(): void {
		$this->write_manifest(
			'js/dist',
			[
				'js/src/index.ts' => [
					'file' => 'index-HASH1.js',
					'css'  => [ 'index-HASH2.css' ],
				],
			]
			);
		$handle = SettingTabService::$handle;
		\wp_deregister_script( $handle );
		\wp_deregister_style( $handle );

		SettingTabService::enqueue_vue_app();

		$this->assertSame( Plugin::$url . '/js/dist/index-HASH1.js', \wp_scripts()->registered[ $handle ]->src );
		$this->assertSame( Plugin::$url . '/js/dist/index-HASH2.css', \wp_styles()->registered[ $handle ]->src );
		$this->assertNull( \wp_scripts()->registered[ $handle ]->ver );

		// 由 enqueue_vue_app()（發票 / 內嵌金流路徑）註冊時也必須輸出 type="module"
		$tag = (string) \apply_filters( 'script_loader_tag', "<script src='x'></script>", $handle, Plugin::$url . '/js/dist/index-HASH1.js' ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- 測試 script_loader_tag 濾鏡輸入
		$this->assertStringContainsString( 'type="module"', $tag );

		\wp_dequeue_script( $handle );
		\wp_dequeue_style( $handle );
		\wp_deregister_script( $handle );
		\wp_deregister_style( $handle );
	}
}
