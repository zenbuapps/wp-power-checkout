<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Shared\Utils;

use J7\PowerCheckout\Plugin;

/**
 * Vite build 產物路徑解析（讀 manifest.json 取得帶 content hash 的檔名）
 *
 * 為什麼需要：部分主機（nginx `Cache-Control: immutable, max-age=31536000`）會忽略 `?ver=`，
 * 固定檔名（`js/dist/index.js`）更新外掛後瀏覽器仍執行一年前的舊 bundle。
 * build 產物改為 `index-{hash}.js`，PHP 由 manifest 找出這次 build 的實際檔名，
 * 內容一變網址就變，快取再兇也拿不到舊檔。
 *
 * manifest 不存在 / 損毀 / 找不到 entry 時退回舊固定檔名（相容尚未重新 build 的開發環境）。
 */
final class ViteAsset {

	/** Vue 設定 App 的 build 目錄（相對外掛根目錄） */
	public const APP_DIST = 'js/dist';

	/** Vue 設定 App 的 entry（manifest key） */
	public const APP_ENTRY = 'js/src/index.ts';

	/** WC Blocks 的 build 目錄（相對外掛根目錄） */
	public const BLOCKS_DIST = 'inc/assets/dist/blocks';

	/** WC Blocks entry 原始碼目錄（manifest key 前綴） */
	public const BLOCKS_SRC = 'inc/assets/blocks';

	/** @var array<string, array<string, mixed>|null> 已讀取的 manifest（依 dist 目錄快取，null = 無可用 manifest） */
	private static array $manifests = [];

	/** @var string|null 外掛根目錄覆寫（僅測試用；null = Plugin::$dir） */
	private static ?string $root = null;

	/**
	 * 取得 entry 的 JS 檔（相對 dist 目錄）
	 *
	 * @param string $dist_dir      build 目錄（相對外掛根目錄）
	 * @param string $entry         manifest key（entry 原始碼路徑，相對外掛根目錄）
	 * @param string $fallback_file 無 manifest 時的舊固定檔名（相對 dist 目錄）
	 * @return string 相對 dist 目錄的檔名
	 */
	public static function entry_file( string $dist_dir, string $entry, string $fallback_file ): string {
		$chunk = self::chunk( $dist_dir, $entry );
		$file  = $chunk['file'] ?? null;
		return \is_string( $file ) && '' !== $file ? $file : $fallback_file;
	}

	/**
	 * 取得 entry 的 CSS 檔清單（相對 dist 目錄）
	 *
	 * @param string             $dist_dir       build 目錄
	 * @param string             $entry          manifest key
	 * @param array<int, string> $fallback_files 無 manifest 時的舊固定檔名
	 * @return array<int, string>
	 */
	public static function entry_css( string $dist_dir, string $entry, array $fallback_files = [] ): array {
		$chunk = self::chunk( $dist_dir, $entry );
		if (null === $chunk) {
			return $fallback_files;
		}
		$css = $chunk['css'] ?? [];
		if (!\is_array( $css )) {
			return [];
		}
		return \array_values( \array_filter( $css, static fn ( $file ): bool => \is_string( $file ) && '' !== $file ) );
	}

	/**
	 * 取得 entry JS 的完整網址
	 *
	 * @param string $dist_dir      build 目錄
	 * @param string $entry         manifest key
	 * @param string $fallback_file 舊固定檔名
	 * @return string
	 */
	public static function entry_url( string $dist_dir, string $entry, string $fallback_file ): string {
		return Plugin::$url . '/' . $dist_dir . '/' . self::entry_file( $dist_dir, $entry, $fallback_file );
	}

	/**
	 * 判斷 entry JS 檔案是否存在於磁碟
	 *
	 * @param string $dist_dir      build 目錄
	 * @param string $entry         manifest key
	 * @param string $fallback_file 舊固定檔名
	 * @return bool
	 */
	public static function entry_exists( string $dist_dir, string $entry, string $fallback_file ): bool {
		return \file_exists( self::root() . '/' . $dist_dir . '/' . self::entry_file( $dist_dir, $entry, $fallback_file ) );
	}

	/**
	 * 傳給 wp_register_script / wp_register_style 的版本參數
	 *
	 * 帶 hash 的檔名已經會隨內容改變，不再加 `?ver=`：
	 * chunk 以 `import '../index-{hash}.js'`（無 query）引用 entry，若 script tag 是 `index-{hash}.js?ver=x`，
	 * 瀏覽器視為兩個不同 module，entry 會被執行兩次。無 manifest（舊固定檔名）時才用外掛版本號。
	 *
	 * @param string $dist_dir build 目錄
	 * @param string $entry    manifest key
	 * @return string|null null = 不加 ?ver=
	 */
	public static function version( string $dist_dir, string $entry ): ?string {
		return null === self::chunk( $dist_dir, $entry ) ? Plugin::$version : null;
	}

	/** @return string|null Vue 設定 App 的版本參數 */
	public static function app_version(): ?string {
		return self::version( self::APP_DIST, self::APP_ENTRY );
	}

	/**
	 * WC Blocks entry 的版本參數
	 *
	 * @param string $name entry 名稱
	 * @return string|null
	 */
	public static function block_version( string $name ): ?string {
		return self::version( self::BLOCKS_DIST, self::block_entry( $name ) );
	}

	/**
	 * Vue 設定 App 的 JS 網址
	 *
	 * @return string
	 */
	public static function app_script_url(): string {
		return self::entry_url( self::APP_DIST, self::APP_ENTRY, 'index.js' );
	}

	/**
	 * Vue 設定 App 的 CSS 網址（取第一個；App 只有一個 entry CSS）
	 *
	 * @return string
	 */
	public static function app_style_url(): string {
		$css  = self::entry_css( self::APP_DIST, self::APP_ENTRY, [ 'index.css' ] );
		$file = $css[0] ?? 'index.css';
		return Plugin::$url . '/' . self::APP_DIST . '/' . $file;
	}

	/**
	 * WC Blocks entry 的 JS 網址
	 *
	 * @param string $name entry 名稱（= inc/assets/blocks/{name}.tsx）
	 * @return string
	 */
	public static function block_script_url( string $name ): string {
		return self::entry_url( self::BLOCKS_DIST, self::block_entry( $name ), "{$name}.js" );
	}

	/**
	 * WC Blocks entry 的 JS 是否已 build
	 *
	 * @param string $name entry 名稱
	 * @return bool
	 */
	public static function block_script_exists( string $name ): bool {
		return self::entry_exists( self::BLOCKS_DIST, self::block_entry( $name ), "{$name}.js" );
	}

	/**
	 * 清除 manifest 快取並指定讀檔根目錄（測試用）
	 *
	 * @param string|null $root 讀 manifest / 檢查檔案存在的根目錄；null = Plugin::$dir
	 * @return void
	 */
	public static function reset( ?string $root = null ): void {
		self::$manifests = [];
		self::$root      = $root;
	}

	/** @return string 讀檔根目錄 */
	private static function root(): string {
		return self::$root ?? Plugin::$dir;
	}

	/**
	 * @param string $name entry 名稱
	 * @return string manifest key
	 */
	private static function block_entry( string $name ): string {
		return self::BLOCKS_SRC . "/{$name}.tsx";
	}

	/**
	 * 取得 manifest 中的 entry chunk
	 *
	 * @param string $dist_dir build 目錄
	 * @param string $entry    manifest key
	 * @return array<string, mixed>|null
	 */
	private static function chunk( string $dist_dir, string $entry ): ?array {
		$manifest = self::manifest( $dist_dir );
		if (null === $manifest) {
			return null;
		}
		$chunk = $manifest[ $entry ] ?? null;
		if (!\is_array( $chunk )) {
			return null;
		}
		/** @var array<string, mixed> $chunk */
		return $chunk;
	}

	/**
	 * 讀取 manifest.json（每個 dist 目錄每 request 只讀一次）
	 *
	 * @param string $dist_dir build 目錄
	 * @return array<string, mixed>|null
	 */
	private static function manifest( string $dist_dir ): ?array {
		if (\array_key_exists( $dist_dir, self::$manifests )) {
			return self::$manifests[ $dist_dir ];
		}

		$manifest = null;
		$path     = self::root() . '/' . $dist_dir . '/manifest.json';
		if (\is_readable( $path )) {
			$raw     = \file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$decoded = \is_string( $raw ) ? \json_decode( $raw, true ) : null;
			if (\is_array( $decoded )) {
				/** @var array<string, mixed> $decoded */
				$manifest = $decoded;
			}
		}

		self::$manifests[ $dist_dir ] = $manifest;
		return $manifest;
	}
}
