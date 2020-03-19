<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020 Edward Chernenko.

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
 * @file
 * Subclass of MediaWikiTestCase that is used for Consequence tests. (NOT for blackbox tests)
 */

use Wikimedia\TestingAccessWrapper;

class ModerationUnitTestCase extends MediaWikiTestCase {
	protected function addCoreDBData() {
		// Do nothing. Normally this method creates test user, etc.,
		// but our unit tests don't need this.
	}

	/**
	 * Workaround for a bug in MediaWiki 1.31 where resetDB() isn't called for the first test
	 * in the class. Therefore we have to clean DB tables manually to prevent leftover data
	 * from being used in the following test.
	 */
	public function addDBDataOnce() {
		global $wgVersion;
		if ( version_compare( $wgVersion, '1.32.0', '<' ) ) {
			foreach ( $this->tablesUsed as $table ) {
				$this->db->delete( $table, '*', __METHOD__ );
			}
		}
	}

	/**
	 * Exit "approve mode" and destroy the ApproveHook singleton.
	 */
	public function tearDown() : void {
		// If the previous test used Approve, it enabled "all edits should bypass moderation" mode.
		// Disable it now.
		$canSkip = TestingAccessWrapper::newFromClass( ModerationCanSkip::class );
		$canSkip->inApprove = false;

		// Forget about previous ApproveHook tasks by destroying the object with their list.
		ModerationApproveHook::destroySingleton();

		parent::tearDown();
	}
}
