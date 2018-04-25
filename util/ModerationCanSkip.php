<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2018 Edward Chernenko.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
*/

/**
	@file
	@brief Checks if the user is allowed to skip moderation.
*/

class ModerationCanSkip {
	protected static $inApprove = false; /**< Flag used in enterApproveMode() */

	/**
		@brief Enters "approve mode", making all further calls of canSkip() return true.
		This is used in ModerationActionApprove, so that newly approved edit
		wouldn't be stopped by Moderation again.
	*/
	public static function enterApproveMode() {
		self::$inApprove = true;
	}

	/**
		@brief Check if $user can skip moderation in namespace $namespaceNumber.
		@param $namespaceNumber2 If set, BOTH namespaces must be non-moderated to skip moderation.
	*/
	public static function canSkip( User $user, $namespaceNumber, $namespaceNumber2 = null ) {
		global $wgModerationEnable;

		/*
			NOTE: it makes little sense for some user to have 'rollback'
			and not have 'skip-moderation', and there is no perfect
			implementation for this case.
			It is much better to allow all rollbacks to skip moderation.
		*/
		if (
			!$wgModerationEnable ||
			self::$inApprove ||
			$user->isAllowed( 'skip-moderation' ) ||
			$user->isAllowed( 'rollback' )
		)
		{
			return true;
		}

		/* Is moderation disabled in affected namespace(s)? */
		$canSkipInNs = self::canSkipInNamespace( $namespaceNumber );
		if ( $canSkipInNs && !is_null( $namespaceNumber2 ) && $namespaceNumber2 != $namespaceNumber ) {
			/* When renaming pages from one namespace to another,
				both source and target namespace must be non-moderated
				for moderation to be skipped. */
			$canSkipInNs = self::canSkipInNamespace( $namespaceNumber2 );
		}

		return $canSkipInNs;
	}

	/**
		@brief Check if moderation can be skipped in namespace $namespaceNumber.
	*/
	public static function canSkipInNamespace( $namespaceNumber ) {
		global $wgModerationOnlyInNamespaces,
			$wgModerationIgnoredInNamespaces;

		if ( in_array( $namespaceNumber, $wgModerationIgnoredInNamespaces ) ) {
			return true; /* This namespace is NOT moderated, e.g. Sandbox:Something */
		}

		if ( $wgModerationOnlyInNamespaces && !in_array( $namespaceNumber, $wgModerationOnlyInNamespaces ) ) {
			return true; /* This namespace is NOT moderated */
		}

		return false;
	}
}
