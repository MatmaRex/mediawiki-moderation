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
 * Unit test of InvalidatePendingTimeCacheConsequence.
 */

use MediaWiki\Moderation\InvalidatePendingTimeCacheConsequence;
use Wikimedia\TestingAccessWrapper;

class InvalidatePendingTimeCacheConsequenceTest extends MediaWikiTestCase {
	/**
	 * Verify that InvalidatePendingTimeCacheConsequence invalidates the cache
	 * used by ModerationNotifyModerator::getPendingTime().
	 * @covers MediaWiki\Moderation\InvalidatePendingTimeCacheConsequence
	 */
	public function testPendingTimeCacheInvalidated() {
		// This test requires working Cache (not EmptyBagOStuff, which is default for tests).
		$this->setMwGlobals( 'wgMainCacheType', 'hash' );

		// First, place some value into the cache and verify that getPendingTime() returns it.
		$timestamp = wfTimestampNow();
		ModerationNotifyModerator::setPendingTime( $timestamp );

		$accessWrapper = TestingAccessWrapper::newFromObject( new ModerationNotifyModerator );
		$this->assertEquals( $timestamp, $accessWrapper->getPendingTime() );

		// Create and run the Consequence.
		$consequence = new InvalidatePendingTimeCacheConsequence();
		$consequence->run();

		// Now verify that the previously cached result is no longer returned by getPendingTime().
		$this->assertNotEquals( $timestamp, $accessWrapper->getPendingTime() );
	}
}
