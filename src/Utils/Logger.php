<?php
/**
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\Utils;

class Logger {

	public const LOG_LEVEL_BASE = 0;
	public const LOG_LEVEL_ERROR = 1;
	public const LOG_LEVEL_WARNING = 2;
	public const LOG_LEVEL_NOTICE = 3;
	public const LOG_LEVEL_INFO = 4;
	public const LOG_LEVEL_DEBUG = 5;

	public static function is_enabled(): bool {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return false;
		}
		return true;
	}

	private static function resolve_log_level(): int {
		return defined( 'ZS_WXE_DEBUG_LOG_LEVEL' ) ? ZS_WXE_DEBUG_LOG_LEVEL : self::LOG_LEVEL_ERROR;
	}

	public static function log( mixed $what, int $log_level = self::LOG_LEVEL_BASE ): bool {
		if ( ! self::is_enabled() || $log_level > self::resolve_log_level() ) {
			return false;
		}

		$message = is_string( $what ) ? $what : print_r( $what, true );
		error_log( $message );
		return true;
	}

	public static function debug( string $message, array $context = array() ): void {
		self::log( self::format_message( $message, $context ), self::LOG_LEVEL_DEBUG );
	}

	public static function info( string $message, array $context = array() ): void {
		self::log( self::format_message( $message, $context ), self::LOG_LEVEL_INFO );
	}

	public static function notice( string $message, array $context = array() ): void {
		self::log( self::format_message( $message, $context ), self::LOG_LEVEL_NOTICE );
	}

	public static function warning( string $message, array $context = array() ): void {
		self::log( self::format_message( $message, $context ), self::LOG_LEVEL_WARNING );
	}

	public static function error( string $message, array $context = array() ): void {
		self::log( self::format_message( $message, $context ), self::LOG_LEVEL_ERROR );
	}

	private static function format_message( string $message, array $context ): string {
		$formatted = sprintf( '[%s] [WXE] %s', gmdate( 'd-M-Y H:i:s' ), $message );

		if ( ! empty( $context ) ) {
			$formatted .= "\n" . print_r( $context, true );
		}

		return $formatted;
	}
}
