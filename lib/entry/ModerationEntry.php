<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018 Edward Chernenko.

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
	@brief Parent class for all entry types (edit, upload, move, etc.).
*/

abstract class ModerationEntry {
	private $row;

	private $user = null; /**< Author of this change (User object) */
	private $title = null; /**< Page affected by this change (Title object) */

	protected function getRow() {
		return $this->row;
	}

	protected function __construct( $row ) {
		$this->row = $row;
	}

	/**
		@brief Returns author of this change (User object).
	*/
	protected function getUser() {
		if ( is_null( $this->user ) ) {
			$row = $this->getRow();
			$this->user = $row->user ?
				User::newFromId( $row->user ) :
				User::newFromName( $row->user_text, false );

			/* User could have been recently renamed or deleted.
				Make sure we have the correct data. */
			$this->user->load( User::READ_LATEST );
		}

		return $this->user;
	}

	/**
		@brief Return username (string) of author.
		Works even if author's user account was deleted.
	*/
	protected function getUserDisplayName() {
		$user = $this->getUser();
		$row = $this->getRow();

		if ( $user->getId() == 0 && $row->user != 0 ) {
			/* User was deleted,
				e.g. via [maintenance/removeUnusedAccounts.php] */
			return $row->user_text;
		}

		return $user->getName();
	}

	/**
		@brief Returns Title of the page affected by this change.
	*/
	public function getTitle() {
		if ( is_null( $this->title ) ) {
			$row = $this->getRow();
			$this->title = Title::makeTitle( $row->namespace, $row->title );
		}

		return $this->title;
	}

	/**
		@brief Load ModerationEntry from the database by mod_id.
		@throws ModerationError
	*/
	public static function newFromId( $id ) {
		$fields = [
			'mod_id AS id',
			'mod_timestamp AS timestamp',
			'mod_user AS user',
			'mod_user_text AS user_text',
			'mod_cur_id AS cur_id',
			'mod_namespace AS namespace',
			'mod_title AS title',
			'mod_comment AS comment',
			'mod_minor AS minor',
			'mod_bot AS bot',
			'mod_last_oldid AS last_oldid',
			'mod_ip AS ip',
			'mod_header_xff AS header_xff',
			'mod_header_ua AS header_ua',
			'mod_text AS text',
			'mod_merged_revid AS merged_revid',
			'mod_rejected AS rejected',
			'mod_stash_key AS stash_key'
		];

		if ( ModerationVersionCheck::areTagsSupported() ) {
			$fields[] = 'mod_tags AS tags';
		}

		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation',
			$fields,
			[ 'mod_id' => $id ],
			__METHOD__
		);
		if ( !$row ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		return self::newFromRow( $row );
	}

	/**
		@brief Construct new ModerationEntry from $row.
		@throws ModerationError
	*/
	public static function newFromRow( $row ) {
		if ( $row->stash_key ) {
			return new ModerationEntryUpload( $row );
		}

		return new ModerationEntryEdit( $row );
	}

	/**
		@brief Throws ModerationError if $row is not approvable.
	*/
	protected function assertApprovable() {
		$row = $this->getRow();

		if ( $row->merged_revid ) {
			throw new ModerationError( 'moderation-already-merged' );
		}

		if ( $row->rejected && $row->timestamp < SpecialModeration::getEarliestReapprovableTimestamp() ) {
			throw new ModerationError( 'moderation-rejected-long-ago' );
		}
	}

	/**
		@brief Install hooks which affect postedit behavior of doEditContent().
	*/
	protected function installApproveHook() {
		$row = $this->getRow();
		$user = $this->getUser();

		$dbr = wfGetDB( DB_SLAVE ); /* Only for $dbr->timestamp(), won't do any SQL queries */

		ModerationApproveHook::install( $this->getTitle(), $user, [
			# For CheckUser extension to work properly, IP, XFF and UA
			# should be set to the correct values for the original user
			# (not from the moderator)
			'ip' => $row->ip,
			'xff' => $row->header_xff,
			'ua' => $row->header_ua,
			'tags' => ModerationVersionCheck::areTagsSupported() ? $row->tags : false,

			'revisionUpdate' => [
				# Here we set the timestamp of this edit to $row->timestamp
				# (this is needed because doEditContent() always uses current timestamp).
				#
				# NOTE: timestamp in recentchanges table is not updated on purpose:
				# users would want to see new edits as they appear,
				# without the edits surprisingly appearing somewhere in the past.
				'rev_timestamp' => $dbr->timestamp( $row->timestamp ),

				# performUpload() mistakenly tags image reuploads as made by moderator (rather than $user).
				# Let's fix this here.
				'rev_user' => $user->getId(),
				'rev_user_text' => $this->getUserDisplayName()
			]
		] );
	}

	/**
		@brief Approve this change.
		@throws ModerationError
	*/
	final public function approve() {
		$this->assertApprovable();

		# Disable moderation hook (ModerationEditHooks::onPageContentSave),
		# so that it won't queue this edit again.
		ModerationCanSkip::enterApproveMode();

		# Install hooks to modify CheckUser database after approval, etc.
		$this->installApproveHook();

		# Do the actual approval.
		return $this->doApprove();
	}

	/**
		@brief Approve this change.
		@returns Status object.
		@throws ModerationError
	*/
	abstract public function doApprove();
}
