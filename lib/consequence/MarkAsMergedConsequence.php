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
 * Consequence that marks one pending change as merged.
 */

namespace MediaWiki\Moderation;

use ModerationVersionCheck;

class MarkAsMergedConsequence implements IConsequence {
	/** @var int */
	protected $modid;

	/** @var int */
	protected $revid;

	/**
	 * @param int $modid
	 * @param int $revid
	 */
	public function __construct( $modid, $revid ) {
		$this->modid = $modid;
		$this->revid = $revid;
	}

	/**
	 * Execute the consequence.
	 * @return bool True if non-merged edit was marked as merged, false otherwise.
	 */
	public function run() {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			[
				'mod_merged_revid' => $this->revid,
				ModerationVersionCheck::setPreloadableToNo()
			],
			[
				'mod_id' => $this->modid,
				'mod_merged_revid' => 0 # No more than one merging
			],
			__METHOD__
		);

		return ( $dbw->affectedRows() > 0 );
	}
}
