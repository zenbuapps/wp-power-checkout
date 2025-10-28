<?php
/**
 * Base
 */

declare (strict_types = 1);

namespace J7\PowerCheckout\Utils;

/**
 * Class Utils
 */
abstract class Base {
	const BASE_URL      = '/';
	const APP1_SELECTOR = '#power-checkout-wc-setting-app';
	const API_TIMEOUT   = '30000';
	const DEFAULT_IMAGE = 'http://1.gravatar.com/avatar/1c39955b5fe5ae1bf51a77642f052848?s=96&d=mm&r=g';
}
