<?php

/**
 * Provides an API module to process a single page with the LinkTitles extension.
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

/// @cond
if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'Not an entry point.' );
}
/// @endcond

/**
 * API module that allows processing a single wiki page with LinkTitles.
 *
 * Usage:
 *   api.php?action=linktitles&page=PageName&token=...
 *
 * Requires the 'linktitles-batch' right (sysop by default).
 */
class ApiLinkTitles extends \ApiBase {

	public function execute() {
		// Require the linktitles-batch right
		$this->checkUserRightsAny( 'linktitles-batch' );

		$params = $this->extractRequestParams();
		$pageName = $params['page'];

		$title = \Title::newFromText( $pageName );
		if ( !$title || !$title->exists() ) {
			$this->dieWithError(
				[ 'apierror-invalidtitle', wfEscapeWikiText( $pageName ) ]
			);
		}

		$context = \RequestContext::getMain();
		$success = Extension::processPage( $title, $context );

		$result = $this->getResult();
		$result->addValue( null, 'linktitles', [
			'result' => $success ? 'success' : 'failure',
			'page'   => $title->getPrefixedText(),
		] );
	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	public function needsToken() {
		return 'csrf';
	}

	public function getAllowedParams() {
		return [
			'page' => [
				\ApiBase::PARAM_TYPE     => 'string',
				\ApiBase::PARAM_REQUIRED => true,
			],
		];
	}

	protected function getExamplesMessages() {
		return [
			'action=linktitles&page=Main_Page&token=TOKEN'
				=> 'apihelp-linktitles-example-page',
		];
	}

	public function getHelpUrls() {
		return Extension::URL;
	}
}
