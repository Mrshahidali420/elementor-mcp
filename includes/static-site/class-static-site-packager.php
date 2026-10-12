<?php
/**
 * Resumable ZIP packaging (spec 4.3). PHP writes an archive only on close(), so
 * rows are marked only after close() succeeded, and every open reconciles rows
 * whose entry is already present.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Static_Site_Packager {

	/** @var callable|null Test seam returning a ZipArchive-like object. */
	public static $factory = null;

	public static function zip_batch( string $zip_path, string $root, array $rows, callable $mark, float $deadline ): array {
		$zip = null !== self::$factory ? ( self::$factory )() : new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
			return array( 'added' => 0, 'error' => 'zip_open_failed' );
		}
		$start   = microtime( true );
		$stop    = $start + 0.6 * max( 1.0, $deadline - $start );
		$present = array();
		$added   = array();
		$missing = array();
		$failed  = array();
		foreach ( $rows as $row ) {
			$name = (string) $row['file_path'];
			if ( false !== $zip->locateName( $name ) ) {
				$present[] = (int) $row['id'];
				continue;
			}
			if ( microtime( true ) >= $stop ) {
				break;
			}
			$file = rtrim( $root, '/' ) . '/' . $name;
			if ( ! is_file( $file ) ) {
				$missing[] = (int) $row['id'];
				continue;
			}
			// A refused file is reported, never left saved: Package would retry it forever.
			if ( $zip->addFile( $file, $name ) ) {
				$added[] = (int) $row['id'];
			} else {
				$failed[] = (int) $row['id'];
			}
		}
		if ( true !== $zip->close() ) {
			return array( 'added' => 0, 'error' => 'zip_close_failed' );
		}
		$mark( array_merge( $present, $added ), $missing, $failed );
		return array( 'added' => count( $added ), 'error' => '' );
	}
}
