<?php
/**
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\Utils;

/**
 * Static logger with configurable verbosity via ZS_WXE_DEBUG_LOG_LEVEL.
 *
 * Writes to the WP debug log when WP_DEBUG and WP_DEBUG_LOG are both enabled.
 */
class Logger {

	public const LOG_LEVEL_BASE = 0;
	public const LOG_LEVEL_ERROR = 1;
	public const LOG_LEVEL_WARNING = 2;
	public const LOG_LEVEL_NOTICE = 3;
	public const LOG_LEVEL_INFO = 4;
	public const LOG_LEVEL_DEBUG = 5;

	/**
	 * Whether debug logging is enabled (WP_DEBUG + WP_DEBUG_LOG).
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return false;
		}
		return true;
	}

	/**
	 * Resolve the active log level from the ZS_WXE_DEBUG_LOG_LEVEL constant.
	 *
	 * @return int
	 */
	private static function resolve_log_level(): int {
		return defined( 'ZS_WXE_DEBUG_LOG_LEVEL' ) ? ZS_WXE_DEBUG_LOG_LEVEL : self::LOG_LEVEL_ERROR;
	}

	/**
	 * Write a message to the debug log if the given level is within threshold.
	 *
	 * @param mixed $what      Message string or data to log.
	 * @param int   $log_level Log level for this entry.
	 * @return bool Whether the message was written.
	 */
	public static function log( mixed $what, int $log_level = self::LOG_LEVEL_BASE ): bool {
		if ( ! self::is_enabled() || $log_level > self::resolve_log_level() ) {
			return false;
		}

		$message = is_string( $what ) ? $what : print_r( $what, true );
		error_log( $message );
		return true;
	}

	/**
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context data.
	 */
	public static function debug( string $message, array $context = array() ): void {
		self::log( self::format_message( $message, $context ), self::LOG_LEVEL_DEBUG );
	}

	/**
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context data.
	 */
	public static function info( string $message, array $context = array() ): void {
		self::log( self::format_message( $message, $context ), self::LOG_LEVEL_INFO );
	}

	/**
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context data.
	 */
	public static function notice( string $message, array $context = array() ): void {
		self::log( self::format_message( $message, $context ), self::LOG_LEVEL_NOTICE );
	}

	/**
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context data.
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::log( self::format_message( $message, $context ), self::LOG_LEVEL_WARNING );
	}

	/**
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context data.
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( self::format_message( $message, $context ), self::LOG_LEVEL_ERROR );
	}

	/**
	 * Format a log entry with timestamp and optional context.
	 *
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Additional context data.
	 * @return string
	 */
	private static function format_message( string $message, array $context ): string {
		$formatted = sprintf( '[%s] [WXE] %s', gmdate( 'd-M-Y H:i:s' ), $message );

		if ( ! empty( $context ) ) {
			$formatted .= "\n" . print_r( $context, true );
		}

		return $formatted;
	}
}
