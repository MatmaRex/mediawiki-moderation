<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018-2020 Edward Chernenko.

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
 * Parent class for TestSet objects used in the Moderation testsuite.
 */

trait ModerationTestsuiteTestSet {
	/**
	 * @var bool
	 * True if this is a temporary set created in runSet(), false otherwise.
	 */
	protected $cloned = false;

	/**
	 * Run this TestSet from input of dataProvider.
	 * @param array $options Parameters of test, e.g. [ 'user' => '...', 'title' => '...' ].
	 */
	public function runSet( array $options ) {
		// Clone the set before each test.
		// This is needed to prevent properties from being preserved between runs.
		$set = clone $this;
		$set->cloned = true;

		$set->applyOptions( $options );
		$set->makeChanges();
		$set->assertResults();
	}

	public function __destruct() {
		// Destructor should be suppressed for cloned MediaWikiTestCase objects.
		if ( !$this->cloned ) {
			parent::__destruct();
		}
	}

	/*-------------------------------------------------------------------*/

	/**
	 * Initialize this TestSet from the input of dataProvider.
	 */
	abstract protected function applyOptions( array $options );

	/**
	 * Execute this TestSet, making the edit with requested parameters.
	 */
	abstract protected function makeChanges();

	/**
	 * Assert whether the situation after the edit is correct or not.
	 */
	abstract protected function assertResults();

	/*-------------------------------------------------------------------*/

	/**
	 * Assert that $timestamp is a realistic time for changes made during this test.
	 * @param string $timestamp Timestamp in MediaWiki format (14 digits).
	 */
	protected function assertTimestampIsRecent( $timestamp ) {
		// How many seconds ago are allowed without failing the assertion.
		$allowedRangeInSeconds = 60;

		$this->assertLessThanOrEqual(
			wfTimestampNow(),
			$timestamp,
			'assertTimestampIsRecent(): timestamp of existing change is in the future.'
		);

		$ts = new MWTimestamp();
		$ts->timestamp->modify( "- $allowedRangeInSeconds seconds" );
		$minTimestamp = $ts->getTimestamp( TS_MW );

		$this->assertGreaterThan(
			$minTimestamp,
			$timestamp,
			'assertTimestampIsRecent(): timestamp of recently made change is too far in the past.'
		);
	}

	/**
	 * Assert that recent row in 'moderation' SQL table consists of $expectedFields.
	 * @param array $expectedFields Key-value list of all mod_* fields.
	 * @throws AssertionFailedError
	 * @return stdClass $row
	 */
	protected function assertRowEquals( array $expectedFields ) {
		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation', '*', '', __METHOD__ );

		// Create sorted arrays Expected and Actual and ensure no difference between them.

		$expected = [];
		$actual = [];

		foreach ( $expectedFields as $key => $expectedValue ) {
			$actualValue = $row->$key;

			if ( is_numeric( $actualValue ) ) {
				// DB::selectRow() returns numbers as strings, so we need to cast them to numbers,
				// or else assertEquals() would fail.
				// E.g. "1" => 1.
				$actualValue += 0;
			}

			if ( $expectedValue instanceof ModerationTestSetRegex ) {
				$regex = (string)$expectedValue;
				if ( preg_match( $regex, $actualValue ) ) {
					// This is a trick to display a simple diff of Expected/Actual arrays,
					// even though some of the $expectedFields are regexes (not constants).
					$actualValue .= " (regex: ${regex})";
					$expected[$key] = $actualValue;
				} else {
					$actualValue .= " (DOESN'T MATCH REGEX)";
					$expected[$key] = $regex;
				}
			} else {
				$expected[$key] = $expectedValue;
			}

			$actual[$key] = $actualValue;
		}

		asort( $expected );
		asort( $actual );

		$this->assertEquals( $expected, $actual,
			"Database row doesn't match expected."
		);

		return $row;
	}

	/**
	 * Create an existing page (or file) before the test.
	 * @param Title $title
	 * @param string $initialText
	 * @param string|null $filename If not null, upload another file (NOT $filename) before test.
	 */
	protected function precreatePage( Title $title, $initialText, $filename = null ) {
		$t = $this->getTestsuite();
		$t->loginAs( $t->moderator );

		if ( $filename ) {
			// Important: $filename is the file that will be uploaded by the test itself.
			// We want to pre-upload a different file here, so that attempts
			// to later approve $filename wouldn't fail with (fileexists-no-change).
			$anotherFilename = ( strpos( $filename, 'image100x100.png' ) === false ) ?
				'image100x100.png' : 'image640x50.png';

			$t->getBot( 'api' )->upload(
				$title->getText(),
				$anotherFilename,
				$initialText
			);
		} else {
			// Normal page (not an upload).
			ModerationTestUtil::fastEdit(
				$title,
				$initialText,
				'', // edit summary doesn't matter
				$t->moderator
			);
		}
	}
}
