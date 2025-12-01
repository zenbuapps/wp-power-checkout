<?php /** @noinspection PhpMissingReturnTypeInspection */


declare ( strict_types = 1 );

namespace J7\PowerCheckout\Shared\Abstracts;

/** 電子發票服務抽象類別單例模式 */
abstract class BaseService {

	/** @var string $id Id */
	public string $id = '';

	/** @var string $icon Icon */
	public string $icon = '';

	/** @var string $enabled 是否啟用 yes|no */
	public string $enabled = 'no';

	/** @var string $method_title 標題 */
	public string $method_title = '';

	/** @var string $method_description 描述 */
	public string $method_description = '';

	/** 初始化 */
	public static function init(): void {}


	/**
	 * @param bool $with_default 是否有預設值，還是只拿 DB 值
	 * false = 只拿 db, true = 會給預設值
	 *
	 * @return array 取得設定
	 */
	abstract public static function get_settings( bool $with_default = true ): array;
}
