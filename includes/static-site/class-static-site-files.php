<?php
/**
 * Filesystem helpers for the Static Site module. Plain PHP so tests need no WordPress.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Static_Site_Files {

	const HTACCESS = "# EMCP Static Site: generated files are read through WordPress only.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
	const WEB_CONFIG = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\" /><add accessType=\"Deny\" users=\"*\" /></authorization></security></system.webServer></configuration>\n";

	public static function ensure_dir( string $dir ): bool {
		return is_dir( $dir ) || @mkdir( $dir, 0755, true ) || is_dir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- race with a parallel mkdir is fine.
	}

	/** Deny files in the base directory; rewritten only when missing. */
	public static function protect_base( string $base ): void {
		self::ensure_dir( $base );
		foreach ( array( '.htaccess' => self::HTACCESS, 'web.config' => self::WEB_CONFIG, 'index.html' => '' ) as $name => $body ) {
			if ( ! is_file( $base . '/' . $name ) ) {
				file_put_contents( $base . '/' . $name, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	/** @return int Bytes written. */
	public static function write( string $path, string $data ): int {
		if ( ! self::ensure_dir( dirname( $path ) ) ) {
			throw new RuntimeException( 'write_failed' );
		}
		$written = file_put_contents( $path, $data, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $written ) {
			throw new RuntimeException( 'write_failed' );
		}
		return $written;
	}

	public static function remove_tree( string $dir ): void {
		if ( '' === $dir || ! file_exists( $dir ) ) {
			return;
		}
		if ( is_link( $dir ) || is_file( $dir ) ) {
			@unlink( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $entry ) {
			$path = $entry->getPathname();
			if ( $entry->isDir() && ! $entry->isLink() ) {
				@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	public static function random_hex( int $bytes ): string {
		return bin2hex( random_bytes( $bytes ) );
	}
}
