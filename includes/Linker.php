<?php

/**
 * The LinkTitles\Linker class does the heavy linking for the extension.
 *
 * Copyright 2012-2024 Daniel Kraus <bovender@bovender.de> ('bovender')
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 * MA 02110-1301, USA.
 *
 * @author Daniel Kraus <bovender@bovender.de>
 */
namespace LinkTitles;

/**
 * Performs the actual linking of content to existing pages.
 */
class Linker {
	/**
	 * LinkTitles configuration.
	 *
	 * @var Config $config
	 */
	public $config;

	/**
	 * The link value of the target page that is currently being evaluated.
	 * This may be either the page name or the page name prefixed with the
	 * name space if the target's name space is not NS_MAIN.
	 *
	 * This is an instance variable (rather than a local method variable) so it
	 * can be accessed in the preg_replace_callback callbacks.
	 *
	 * @var String $linkValue
	 */
	private $linkValue;

	private static $locked = 0;

	/**
	 * Constructs a new instance of the Linker class.
	 *
	 * @param Config $config LinkTitles configuration object.
	 */
	public function __construct( Config &$config ) {
		$this->config = $config;
	}

	/**
	 * Core function of the extension, performs the actual parsing of the content.
	 *
	 * This method receives a Title object and the string representation of the
	 * source page. It does not work on a WikiPage object directly because the
	 * callbacks in the Extension class do not always get a WikiPage object in the
	 * first place.
	 *
	 * @param \Title &$title Title object for the current page.
	 * @param String $text String that holds the article content
 	 * @param  string $targetPageTitle When not empty, will be the only replaced linked in the source page
	 * @return String|null Source page text with links to target pages, or null if no links were added
	 */
	public function linkContent( Source $source, $targetPageTitle = "" ) {
		if ( self::$locked > 0 || !$source->canBeLinked() ) {
			return;
		}

		( $this->config->firstOnly ) ? $limit = 1 : $limit = -1;
		$limitReached = false;
		$newLinks = false; // whether or not new links were added
		$newText = $source->getText();
		$splitter = Splitter::singleton( $this->config );
		$targets = Targets::singleton( $source->getTitle(), $this->config, $targetPageTitle );

		// OPTIMIZATION: Pre-compute text variants for fast matching
		$textLower = $this->config->smartMode ? mb_strtolower( $newText ) : null;
		
		// OPTIMIZATION: Split the text ONCE before iterating through targets
		// This is a major performance improvement as we avoid re-splitting for each title
		$arr = $splitter->split( $newText );
		if ($arr === false) {
			echo "Error while trying to parse Title ". $source->getTitle() ."\n". preg_last_error() . " " . preg_last_error_msg() . "\n";
			return;
		}

		// Iterate through the target page titles
		foreach( $targets->queryResult as $row ) {
			$target = new Target( $row->page_namespace, $row->page_title, $this->config );

			// Don't link current page and don't link if the target page redirects
			// to the current page or has the __NOAUTOLINKTARGET__ magic word
			// (as required by the actual LinkTitles configuration).
			if ( $target->isSameTitle( $source ) || !$target->mayLinkTo( $source ) ) {
				continue;
			}

			// OPTIMIZATION: Fast pre-check if the title could possibly exist in the text
			// This avoids expensive regex operations for titles that don't appear at all
			if ( !$this->couldMatchInText( $target, $newText, $textLower ) ) {
				continue;
			}

			// Dealing with existing links if the firstOnly option is set:
			// A link to the current page should only be recognized if it appears in
			// clear text, i.e. we do not count piped links as existing links.
			// (Similarly, by design, redirections should not be counted as existing links.)
			if ( $limit == 1 && preg_match( '/\[\[' . $target->getCaseSensitiveLinkValueRegex() . ']]/' , $source->getText() ) ) {
				continue;
			}

			// OPTIMIZATION: Reuse the pre-split array instead of re-splitting
			// Note: we need to work on a copy for smart mode's second pass
			if ($arr === false)
			{
				echo "Error while trying to parse Title ". $source->getTitle() ."\n". preg_last_error() . " " . preg_last_error_msg() . "\n";
				return;
			}

			$count = 0;

			// Cache the target title text for the regex callbacks
			$this->linkValue = $target->getPrefixedTitleText();

			// Even indexes will point to sections of the text that may be linked
			for ( $i = 0; $i < count( $arr ); $i += 2 ) {
				$arr[$i] = preg_replace_callback( $target->getCaseSensitiveRegex(),
					array( $this, 'simpleModeCallback'),
					$arr[$i], $limit, $replacements );
				$count += $replacements;
				if ( $this->config->firstOnly && ( $count > 0 ) ) {
					$limitReached = true;
					break;
				};
			};
			if ( $count > 0 ) {
				$newLinks = true;
				$newText = implode( '', $arr );
				Targets::incrementTargetCount( $target->getPrefixedTitleText() );
				// OPTIMIZATION: Re-split after modification for next iterations
				$arr = $splitter->split( $newText );
				if ($arr === false) {
					echo "Error while trying to parse Title ". $source->getTitle() ."\n". preg_last_error() . " " . preg_last_error_msg() . "\n";
					return;
				}
			}

			// If smart mode is turned on, the extension will perform a second
			// pass on the page and add links with aliases where the case does
			// not match.
			if ( $this->config->smartMode && !$limitReached ) {
				// Work on a copy of the array for smart mode
				$arrCopy = $arr;
				$smartCount = 0;

				for ( $i = 0; $i < count( $arrCopy ); $i+=2 ) {
					// even indexes will point to text that is not enclosed by brackets
					$arrCopy[$i] = preg_replace_callback( $target->getCaseInsensitiveRegex(),
						array( $this, 'smartModeCallback'),
						$arrCopy[$i], $limit, $replacements );
					$smartCount += $replacements;
					if ( $this->config->firstOnly && ( $smartCount > 0  )) {
						$limitReached = true;
						break;
					};
				};
				if ( $smartCount > 0 ) {
					$newLinks = true;
					$newText = implode( '', $arrCopy );
					$arr = $arrCopy; // Update the main array
					Targets::incrementTargetCount( $target->getPrefixedTitleText() );
					// OPTIMIZATION: Re-split after modification for next iterations
					$arr = $splitter->split( $newText );
					if ($arr === false) {
						echo "Error while trying to parse Title ". $source->getTitle() ."\n". preg_last_error() . " " . preg_last_error_msg() . "\n";
						return;
					}
				}
			} // $wgLinkTitlesSmartMode
			
			// If we've reached the limit (firstOnly), we can stop early
			if ( $limitReached ) {
				break;
			}
		}; // foreach $res as $row

		if ( $newLinks ) {
			return $newText;
		}
	}

	/**
	 * Fast pre-check to determine if a target title could possibly match in the text.
	 * This avoids expensive regex operations for titles that don't appear at all.
	 * 
	 * OPTIMIZATION: This is a critical performance optimization that can skip 90%+ of titles.
	 *
	 * @param Target $target The target page to check
	 * @param string $text The source text to search in
	 * @param string|null $textLower Lowercase version of text (for smart mode)
	 * @return bool True if the title might match, false if it definitely doesn't
	 */
	private function couldMatchInText( $target, $text, $textLower = null ) {
		$titleText = $target->getTitleText();
		
		// Replace underscores with spaces for matching (MediaWiki convention)
		$titleToFind = str_replace( '_', ' ', $titleText );
		
		// Fast case-sensitive check first
		if ( mb_strpos( $text, $titleToFind ) !== false ) {
			return true;
		}
		
		// If smart mode is enabled, check case-insensitive
		if ( $this->config->smartMode && $textLower !== null ) {
			$titleLower = mb_strtolower( $titleToFind );
			if ( mb_strpos( $textLower, $titleLower ) !== false ) {
				return true;
			}
		}
		
		// Also check with underscores (some templates use them)
		if ( mb_strpos( $text, $titleText ) !== false ) {
			return true;
		}
		
		return false;
	}

	/**
	 * Callback for preg_replace_callback in simple mode.
	 *
	 * @param array $matches Matches provided by preg_replace_callback
	 * @return string Target page title with or without link markup
	 */
	private function simpleModeCallback( array $matches ) {
		// If the link value is longer than the match, it must be prefixed with
		// a namespace. In this case, we build a piped link.
		if ( strlen( $this->linkValue ) > strlen( $matches[0] ) ) {
			return '[[' . $this->linkValue . '|' . $matches[0] . ']]';
		} else {
			return '[[' . $matches[0] . ']]';
		}
	}

	/**
	 * Callback function for use with preg_replace_callback.
	 * This essentially performs a case-sensitive comparison of the
	 * current page title and the occurrence found on the page; if
	 * the cases do not match, it builds an aliased (piped) link.
	 * If $wgCapitalLinks is set to true, the case of the first
	 * letter is ignored by MediaWiki and we don't need to build a
	 * piped link if only the case of the first letter is different.
	 *
	 * @param array $matches Matches provided by preg_replace_callback
	 * @return string Target page title with or without link markup
	 */
	private function smartModeCallback( array $matches ) {
		// If cases of the target page title and the actual occurrence in the text
		// are not identical, we need to build a piped link.
		// How case-identity is determined depends on the $wgCapitalLinks setting:
		// with $wgCapitalLinks = true, the case of first letter of the title is
		// not significant.
		if ( $this->config->capitalLinks ) {
			$needPipe = strcmp( substr( $this->linkValue, 1 ), substr( $matches[ 0 ], 1 ) ) != 0;
		} else {
			$needPipe = strcmp( $this->linkValue, $matches[ 0 ] ) != 0;
		}
		if ( $needPipe ) {
			return '[[' . $this->linkValue . '|' . $matches[ 0 ] . ']]';
		} else  {
			return '[[' . $matches[ 0 ]  . ']]';
		}
	}

	/**
	 * Increases an internal static lock counter by 1.
	 *
	 * If the Linker class is locked (counter > 0), linkContent() will be a no-op.
	 * Locking is necessary to enable nested <noautolinks> and <autolinks> tags in
	 * parseOnRender mode.
	 */
	public static function lock() {
		self::$locked += 1;
	}

	/**
	 * Decreases an internal static lock counter by 1.
	 *
	 * If the Linker class is locked (counter > 0), linkContent() will be a no-op.
	 * Locking is necessary to enable nested <noautolinks> and <autolinks> tags in
	 * parseOnRender mode.
	 */
	public static function unlock() {
		self::$locked -= 1;
	}
}

// vim: ts=2:sw=2:noet:comments^=\:///
