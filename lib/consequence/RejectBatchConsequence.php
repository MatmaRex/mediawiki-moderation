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
 * Consequence that marks several pending changes as rejected.
 */

namespace MediaWiki\Moderation;

use ModerationVersionCheck;
use User;

class RejectBatchConsequence implements IConsequence {
	/** @var int[] */
	protected $ids;

	/** @var User */
	protected $moderator;

	/**
	 * @param int[] $ids Array of mod_id values of changes that should be rejected.
	 * @param User $moderator
	 */
	public function __construct( $ids, User $moderator ) {
		$this->ids = $ids;
		$this->moderator = $moderator;
	}

	/**
	 * Execute the consequence.
	 * @return int Number of newly rejected edits.
	 */
	public function run() {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			[
				'mod_rejected' => 1,
				'mod_rejected_by_user' => $this->moderator->getId(),
				'mod_rejected_by_user_text' => $this->moderator->getName(),
				'mod_rejected_batch' => 1,
				ModerationVersionCheck::setPreloadableToNo()
			],
			[
				'mod_id' => $this->ids
			],
			__METHOD__
		);

		return $dbw->affectedRows();
	}
}
