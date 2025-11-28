<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Shared\Utils;

/**
 * StrHelper 輔助函數，協助字串轉換、驗證
 *
 * @example
 * // 過濾特殊字元+最大長度10
 * $name = (new Helper( 'Hello, World!' ))->filter()->max( 10 )->value;
 * */
final class StrHelper {

	/** Constructor */
	public function __construct( public string $value, public string $name = '', public ?int $max_length = null ) {
	}

	/**
	 * 計算中文 & 英文 & 數字字數長度
	 *
	 * @return int
	 * @throws \Exception 如果字串長度超過最大長度
	 */
	public function get_strlen( bool $throw_error = false ): int {
		$strlen = \mb_strlen($this->value, 'UTF-8');
		if ( $throw_error ) {
			$this->validate_strlen();
		}
		return $strlen;
	}


	/**
	 * 驗證中文 & 英文 & 數字字數長度
	 *
	 * @return void
	 * @throws \Exception 如果字串長度超過最大長度
	 */
	public function validate_strlen(): void {
		$strlen = \mb_strlen($this->value, 'UTF-8');
		if ( $strlen > $this->max_length ) {
			throw new \Exception("{$this->name} 字串長度不能超過 {$this->max_length} 個字，目前為 {$strlen} 個字");
		}
	}


	/**
	 * 使用正則表達式匹配所有非中文、英文和數字的字符
	 * \p{Han} 匹配所有中文字符
	 * a-zA-Z 匹配所有英文字母
	 * 0-9 匹配所有數字
	 *
	 * @param bool $throw_error 是否拋出異常
	 * @return bool
	 * @throws \Exception 如果字串包含特殊字元
	 */
	public function has_special_char( bool $throw_error = false ): bool {
		$has_special_char = preg_match('/[^\p{Han}a-zA-Z0-9 ]/u', $this->value) === 1;
		if ( $throw_error) {
			$this->validate_special_char();
		}
		return $has_special_char;
	}


	/**
	 * 驗證配所有非中文、英文和數字的字符
	 * \p{Han} 匹配所有中文字符
	 * a-zA-Z 匹配所有英文字母
	 * 0-9 匹配所有數字
	 *
	 * @return void
	 * @throws \Exception 如果字串包含特殊字元
	 */
	public function validate_special_char(): void {
		$has_special_char = preg_match('/[^\p{Han}a-zA-Z0-9 ]/u', $this->value) === 1;
		if ( $has_special_char ) {
			throw new \Exception("不能包含特殊字元，{$this->name}:{$this->value}");
		}
	}

	/**
	 * 過濾掉字串中的所有特殊字符（非中文、英文）
	 *
	 * @return self 處理後的字串，只保留中文、英文和數字
	 */
	public function filter(): self {
		// 使用正則表達式替換所有非中文、英文和數字的字符為空字串
		$this->value = preg_replace('/[^\p{Han}a-zA-Z0-9 ]/u', '', $this->value) ?? '';
		return $this;
	}

	/**
	 * 截取字串到指定長度
	 *
	 * @return self 截取後的字串
	 */
	public function substr(): self {
		if ( null === $this->max_length ) {
			return $this;
		}

		if ( $this->get_strlen() <= $this->max_length ) {
			return $this;
		}

		$this->value = mb_substr( $this->value, 0, $this->max_length, 'UTF-8' );
		return $this;
	}

	/**
	 * 驗證字串長度 & 特殊字元
	 *
	 * @throws \Exception 如果字串長度超過最大長度
	 */
	public function validate(): void {
		$this->validate_strlen();
		$this->validate_special_char();
	}

	/** 取得唯一字串 */
	public static function get_unique_string( $separator = '' ): string {
		$milliseconds = (int) ( new \DateTimeImmutable() )->format( 'Uv' ); // 13位
		return $separator . \wp_unique_id() . $separator . $milliseconds;
	}

	/** 驗證載具 */
	public static function validate_carrier( string $value ): void {
		$pattern = '/^\/[0-9A-Z\+\-\.]{7}$/';
		if (!preg_match($pattern, $value)) {
			throw new \Exception("{$value} 載具格式不符");
		}
	}

	/** 驗證自然人憑證 */
	public static function validate_moica( string $value ): void {
		$pattern = '/^TP[0-9]{14}$/';
		if (!preg_match($pattern, $value)) {
			throw new \Exception("{$value} 自然人憑證格式不符");
		}
	}
}
