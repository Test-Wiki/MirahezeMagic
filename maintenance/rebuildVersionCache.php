<?php

/**
 * Rebuild the version cache.
 *
 * Usage:
 *    php rebuildVersionCache.php
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 *
 * @author Universal Omega
 * @version 3.0
 */

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

/**
 * Maintenance script to rebuild the version cache.
 *
 * @ingroup Maintenance
 * @see GitInfo
 * @see SpecialVersion
 */
class RebuildVersionCache extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Rebuild the version cache' );
		$this->addOption( 'save-gitinfo', 'Save gitinfo.json files' );
	}

	public function execute() {
		$hashConfig = new HashConfig();

		$hashConfig->set( 'ShellRestrictionMethod', false );

		$baseDirectory = MW_INSTALL_PATH;

		$this->saveCache( $baseDirectory );

		$cache = ObjectCache::getInstance( CACHE_ANYTHING );
		$coreId = $this->getGitInfo( $baseDirectory )['headSHA1'] ?? '';

		$queue = array_fill_keys( array_merge(
				glob( $baseDirectory . '/extensions/*/extension*.json' ),
				glob( $baseDirectory . '/extensions/SocialProfile/SocialProfile.php' ),
				glob( $baseDirectory . '/skins/*/skin.json' )
			),
		true );

		foreach ( $queue as $path => $mtime ) {
			$extensionDirectory = dirname( $path );
			$extensionPath = str_replace( '/srv/mediawiki/w', $baseDirectory, $extensionDirectory );

			if ( $this->hasOption( 'save-gitinfo' ) ) {
				$this->saveCache( $extensionPath );
			}

			$memcKey = $cache->makeKey(
				'specialversion-ext-version-text', str_replace( $baseDirectory, '/srv/mediawiki/w', $path ), $coreId
			);

			$cache->delete( $memcKey );
		}
	}

	private function getGitInfo( string $directory ): ?array {
		$gitDir = $directory . '/.git';
		if ( !file_exists( $gitDir ) ) {
			return null;
		}

		$gitInfo = [];

		// Calculate the SHA1 hash of the HEAD commit
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions
		$headSHA1 = trim( shell_exec( "git --git-dir=$gitDir log -1 --format=%H" ) );

		// Get the HEAD
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions
		$head = trim( shell_exec( "git --git-dir=$gitDir symbolic-ref HEAD 2>/dev/null" ) ) ?: $headSHA1;

		if ( !empty( $head ) ) {
			$gitInfo['head'] = $head;
		}

		if ( !empty( $headSHA1 ) ) {
			$gitInfo['headSHA1'] = $headSHA1;
		}

		// Get the date of the HEAD commit
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions
		$headCommitDate = trim( shell_exec( "git --git-dir=$gitDir log -1 --format=%ct" ) );
		if ( !empty( $headCommitDate ) ) {
			$gitInfo['headCommitDate'] = $headCommitDate;
		}

		// Get the branch name
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions
		$branch = trim( shell_exec( "git --git-dir=$gitDir rev-parse --abbrev-ref HEAD" ) );
		if ( !empty( $branch ) ) {
			if ( $branch === 'HEAD' ) {
				$branch = $headSHA1;
			}

			$gitInfo['branch'] = $branch;
		}

		// Get the remote URL
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions
		$remoteURL = trim( shell_exec( "git --git-dir=$gitDir remote get-url origin" ) );
		if ( !empty( $remoteURL ) ) {
			$gitInfo['remoteURL'] = $remoteURL;
		}

		return $gitInfo;
	}

	private function getCacheFilePath( string $repoDir ) {
		$gitInfoCacheDirectory = $this->getConfig()->get( 'GitInfoCacheDirectory' );

		if ( $gitInfoCacheDirectory === false ) {
			$gitInfoCacheDirectory = $this->getConfig()->get( 'CacheDirectory' ) . '/gitinfo';
		}

		$baseDir = $this->getConfig()->get( 'BaseDirectory' );

		if ( $gitInfoCacheDirectory ) {
			// Convert both MW_INSTALL_PATH and $repoDir to canonical paths to protect against
			// MW_INSTALL_PATH having changed between the settings files and runtime.
			$realIP = realpath( $baseDir );
			$repoName = realpath( $repoDir );

			if ( $repoName === false ) {
				// Unit tests use fake path names
				$repoName = $repoDir;
			}

			if ( strpos( $repoName, $realIP ) === 0 ) {
				// Strip MW_INSTALL_PATH from path
				$repoName = substr( $repoName, strlen( $realIP ) );
			}

			// Transform path to git repo to something we can safely embed in
			// a filename
			$repoName = strtr( $repoName, DIRECTORY_SEPARATOR, '-' );
			$fileName = 'info' . $repoName . '.json';
			return "{$gitInfoCacheDirectory}/{$fileName}";
		}

		return "$repoDir/gitinfo.json";
	}

	private function saveCache( string $repoDir ) {
		$cacheDir = dirname( $this->getCacheFilePath( $repoDir ) );
		if ( !( file_exists( $cacheDir ) || wfMkdirParents( $cacheDir, null, __METHOD__ ) )
			|| !is_writable( $cacheDir )
		) {
			throw new MWException( "Unable to create GitInfo cache \"{$cacheDir}\"" );
		}

		file_put_contents( $this->getCacheFilePath( $repoDir ), FormatJson::encode( $this->getGitInfo( $repoDir ) ) );
	}
}

$maintClass = RebuildVersionCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
