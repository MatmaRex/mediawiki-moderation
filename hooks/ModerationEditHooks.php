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
	@brief Hooks related to normal edits.
*/

class ModerationEditHooks {
	public static $LastInsertId = null; /**< mod_id of the last inserted row */
	public static $NewMergeID = null; /** During modaction=merge, this is mod_id of the pending edit which is currently being merged */

	protected static $section = ''; /**< Number of edited section, if any (populated in onEditFilter) */
	protected static $sectionText = null; /**< Text of edited section, if any (populated in onEditFilter) */

	protected static $watchthis = null; /**< Checkbox "Watch this page", if found (populated in onEditFilter) */

	/*
		onEditFilter()
		Save sections-related information, which will then be used in onPageContentSave.
	*/
	public static function onEditFilter( $editor, $text, $section, &$error, $summary )
	{
		if ( $section != '' ) {
			self::$section = $section;
			self::$sectionText = $text;
		}

		self::$watchthis = $editor->watchthis;

		return true;
	}

	/*
		onPageContentSave()
		Intercept normal edits and queue them for moderation.
	*/
	public static function onPageContentSave( &$page, &$user, &$content, &$summary, $is_minor, $is_watch, $section, &$flags, &$status )
	{
		global $wgOut, $wgContLang, $wgModerationNotificationEnable, $wgModerationNotificationNewOnly,
			   $wgModerationEmail, $wgPasswordSender;

		$title = $page->getTitle();
		if ( ModerationCanSkip::canSkip( $user, $title->getNamespace() ) ) {
			return true;
		}

		/*
		 * Allow to intercept moderation process
		 */
		if ( !Hooks::run( 'ModerationIntercept', [
			$page, $user, $content, $summary, $is_minor, $is_watch, $section, $flags, $status
		] ) ) {
			return true;
		}

		/* Some extensions (e.g. Extension:Flow) use customized ContentHandlers.
			They need special handling for Moderation to intercept them properly.

			For example, Flow first creates a comments page and then a comment,
			but if edit in the comments page was sent to moderation, Flow will
			report error because this comments page was not found.

			Unless we add support for the non-standard ContentHandler,
			edits to pages with it can't be queued for moderation.

			NOTE: edits to Flow discussions will bypass moderation.
		*/
		$handler = $page->getContentHandler();
		if ( !is_a( $handler, 'TextContentHandler' ) ) {
			return true;
		}

		$preload = ModerationPreload::singleton();

		$old_content = $page->getContent( Revision::RAW ); // current revision's content
		$request = $user->getRequest();
		$popts = ParserOptions::newFromUserAndLang( $user, $wgContLang );

		$dbw = wfGetDB( DB_MASTER );

		$fields = [
			'mod_timestamp' => $dbw->timestamp( wfTimestampNow() ),
			'mod_user' => $user->getId(),
			'mod_user_text' => $user->getName(),
			'mod_cur_id' => $page->getId(),
			'mod_namespace' => $title->getNamespace(),
			'mod_title' => $title->getText(), /* FIXME (cosmetic): should be getDBKey(), as in other MediaWiki tables.
							Not fixed right away because existing database would need to be updated
							(which can only be done in a release, not a minor version change,
							as noone runs update.php for those).
							TODO: use wasDbUpdatedAfter() for seamless update. */
			'mod_comment' => $summary,
			'mod_minor' => $is_minor,
			'mod_bot' => $flags & EDIT_FORCE_BOT,
			'mod_new' => $page->exists() ? 0 : 1,
			'mod_last_oldid' => $page->getLatest(),
			'mod_ip' => $request->getIP(),
			'mod_old_len' => $old_content ? $old_content->getSize() : 0,
			'mod_new_len' => $content->getSize(),
			'mod_header_xff' => $request->getHeader( 'X-Forwarded-For' ),
			'mod_header_ua' => $request->getHeader( 'User-Agent' ),
			'mod_text' => $content->preSaveTransform( $title, $user, $popts )->getNativeData(),
			'mod_preload_id' => $preload->getId( true ),
			'mod_preloadable' => 1
		];

		if ( class_exists( 'AbuseFilter' )
			&& !empty( AbuseFilter::$tagsToSet )
			&& ModerationVersionCheck::areTagsSupported()
		) {
			/* AbuseFilter wants to assign some tags to this edit.
				Let's store them (they will be used in modaction=approve).
			*/
			$afActionID = join( '-', [
				$title->getPrefixedText(),
				$user->getName(),
				'edit' /* TODO: does this need special handling for uploads? */
			] );

			if ( isset( AbuseFilter::$tagsToSet[$afActionID] ) ) {
				$fields['mod_tags'] = join( "\n", AbuseFilter::$tagsToSet[$afActionID] );
			}
		}

		if ( ModerationBlockCheck::isModerationBlocked( $user ) ) {
			$fields['mod_rejected'] = 1;
			$fields['mod_rejected_by_user'] = 0;
			$fields['mod_rejected_by_user_text'] = wfMessage( 'moderation-blocker' )->inContentLanguage()->text();
			$fields['mod_rejected_auto'] = 1;
			$fields['mod_preloadable'] = 1; # User can still edit this change, so that spammers won't notice that they are blocked
		}

		// Check if we need to update existing row (if this edit is by the same user to the same page)
		$row = $preload->loadUnmoderatedEdit( $title );
		if ( !$row ) { # No unmoderated edits
			/* We use RollbackResistantQuery, because the caller
				of doEditContent() may assume that "moderation-edit-queued"
				is an error and throw MWException, causing database rollback.
			*/
			RollbackResistantQuery::insert( $dbw, [
				'moderation',
				$fields,
				__METHOD__
			] );
			ModerationEditHooks::$LastInsertId = $dbw->insertId();
		} else {
			if ( self::$section != '' ) {
				#
				# We must recalculate $fields['mod_text'] here.
				# Otherwise if the user adds or modifies two (or more) different sections (in consequent edits),
				# then only modification to the last one will be saved,
				# because $content is [old content] PLUS [modified section from the edit].
				#

				# $new_section_content is exactly what the user just wrote in the edit form (one section only).
				$new_section_content = ContentHandler::makeContent( self::$sectionText, null, $content->getModel() );

				# $saved_content is mod_text which is currently written in the "moderation" table of DB.
				$saved_content = ContentHandler::makeContent( $row->text, null, $content->getModel() );

				# $new_content is $saved_content with replaced section.
				$new_content = $saved_content->replaceSection( self::$section, $new_section_content, '' );

				$fields['mod_text'] = $new_content->preSaveTransform( $title, $user, $popts )->getNativeData();
				$fields['mod_new_len'] = $new_content->getSize();
			}

			RollbackResistantQuery::update( $dbw, [
				'moderation',
				$fields,
				[ 'mod_id' => $row->id ],
				__METHOD__
			] );
			ModerationEditHooks::$LastInsertId = $row->id;
		}

		if ( !is_null( self::$watchthis ) && $user->isLoggedIn() ) {
			/* Watch/Unwatch the page immediately:
				watchlist is the user's own business,
				no reason to wait for approval of the edit */
			$watch = (bool) self::$watchthis;

			if ( $watch != $user->isWatched( $title, false ) ) {
				WatchAction::doWatchOrUnwatch( $watch, $title, $user );
			}
		}

		// Run hook to allow other extensions be notified about pending changes
		Hooks::run( 'ModerationPending', [
			$fields, ModerationEditHooks::$LastInsertId
		] );

		// Notify administrator about pending changes
		if ( $wgModerationNotificationEnable ) {
			/*
				$wgModerationNotificationNewOnly:
				if false, notify about all edits,
				if true, notify about new pages.
			*/
			if ( !$wgModerationNotificationNewOnly || !$page->exists() ) {
				$mailer = new UserMailer();
				$to = new MailAddress( $wgModerationEmail );
				$from = new MailAddress( $wgPasswordSender );
				$subject = wfMessage( 'moderation-notification-subject' )->text();
				$content = wfMessage( 'moderation-notification-content',
					$page->getTitle()->getBaseText(),
					$user->getName(),
					SpecialPage::getTitleFor( 'Moderation' )->getFullURL( [
						'modaction' => 'show',
						'modid' => ModerationEditHooks::$LastInsertId
					] )
				)->text();
				$mailer->send( $to, $from, $subject, $content );
			}
		}

		/* Enable in-wiki notification "New changes await moderation" for moderators */
		ModerationNotifyModerator::setPendingTime( $fields['mod_timestamp'] );

		/*
			We have queued this edit for moderation.
			No need to save anything at this point.
			Later (if approved) the edit will be saved via doEditContent().

			Here we just redirect the users back to the page they edited
			(as was the behavior for unmoderated edits).
			Notification "Your edit was successfully sent to moderation"
			will be shown by JavaScript.
		*/
		$wgOut->redirect( self::getRedirectURL( $title, $wgOut ) );

		$status->fatal( 'moderation-edit-queued' );
		return false;
	}

	/**
		@brief Returns the URL to where the user is redirected after successful edit.
		@param $title Title of the article that was edited.
		@param $context Any object that contains current context.
	*/
	protected static function getRedirectURL( Title $title, IContextSource $context ) {
		$query = [ 'modqueued' => 1 ];

		/* Are customized "continue editing" links needed?
			E.g. Special:FormEdit or ?action=formedit from Extension:PageForms. */
		$returnto = null;
		$returntoquery = [];
		Hooks::run( 'ModerationContinueEditingLink', [ &$returnto, &$returntoquery, $title, $context ] );

		if ( $returnto || $returntoquery ) {
			/* Pack into one parameter to simplify the JavaScript part. */
			$query['returnto'] = FormatJSON::encode( [
				$returnto,
				$returntoquery
			] );
		}

		return $title->getFullURL( $query );
	}

	public static function onBeforePageDisplay( &$out, &$skin ) {

		if ( !ModerationCanSkip::canSkip( $out->getUser(), $out->getTitle()->getNamespace() ) ) {
			$out->addModules( [
				'ext.moderation.notify',
				'ext.moderation.notify.desktop'
			] );
			ModerationAjaxHook::add( $out );
		}

		return true;
	}

	/*
		onPageContentSaveComplete()

		If this is a merged edit, then 'wpMergeID' is the ID of moderation entry.
		Here we mark this entry as merged.
	*/
	public static function onPageContentSaveComplete( $page, $user, $content, $summary, $is_minor, $is_watch, $section, $flags, $revision, $status, $baseRevId )
	{
		global $wgRequest;

		if ( !$revision ) { # Double edit - nothing to do on the second time
			return true;
		}

		/* Only moderators can merge. If someone else adds wpMergeID to the edit form, ignore it */
		if ( !$user->isAllowed( 'moderation' ) ) {
			return true;
		}

		$mergeID = $wgRequest->getVal( 'wpMergeID' );
		if ( !$mergeID ) {
			return true;
		}

		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			[
				'mod_merged_revid' => $revision->getId(),
				'mod_preloadable' => 0
			],
			[
				'mod_id' => $mergeID,
				'mod_merged_revid' => 0 # No more than one merging
			],
			__METHOD__
		);

		if ( $dbw->affectedRows() ) {
			$logEntry = new ManualLogEntry( 'moderation', 'merge' );
			$logEntry->setPerformer( $user );
			$logEntry->setTarget( $page->getTitle() );
			$logEntry->setParameters( [
				'modid' => $mergeID,
				'revid' => $revision->getId()
			] );
			$logid = $logEntry->insert();
			$logEntry->publish( $logid );

			/* Clear the cache of "Most recent mod_timestamp of pending edit"
				- could have changed */
			ModerationNotifyModerator::invalidatePendingTime();
		}

		return true;
	}

	public static function PrepareEditForm( $editpage, $out ) {
		$mergeID = ModerationEditHooks::$NewMergeID;
		if ( !$mergeID ) {
			$mergeID = $out->getRequest()->getVal( 'wpMergeID' );
		}

		if ( !$mergeID ) {
			return;
		}

		$out->addHTML( Html::hidden( 'wpMergeID', $mergeID ) );
		$out->addHTML( Html::hidden( 'wpIgnoreBlankSummary', '1' ) );

		return true;
	}
}
