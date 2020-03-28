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
 * Unit test of ApproveMoveConsequence.
 */

use MediaWiki\Moderation\ApproveMoveConsequence;

require_once __DIR__ . "/autoload.php";

/**
 * @group Database
 */
class ApproveMoveConsequenceTest extends ModerationUnitTestCase {
	use MakeEditTestTrait;

	/** @var string[] */
	protected $tablesUsed = [ 'user', 'page', 'logging', 'log_search' ];

	/**
	 * Verify that ApproveMoveConsequence renames the page.
	 * @covers MediaWiki\Moderation\ApproveMoveConsequence
	 */
	public function testApproveMove() {
		$moderator = self::getTestUser( [ 'moderator', 'automoderated' ] )->getUser();
		$user = self::getTestUser()->getUser();
		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$newTitle = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) . '-new' );
		$reason = 'Some reasons why the page was renamed';

		// Precreate the page that will be renamed.
		$this->makeEdit( $title, $moderator );

		// Monitor TitleMoveComplete hook
		$hookFired = false;
		$this->setTemporaryHook( 'TitleMoveComplete',
			function ( $hookTitle, $hookNewTitle, $hookUser, $pageid, $redirid, $hookReason )
			use ( $user, $title, $reason, &$hookFired ) {
				$hookFired = true;

				$this->assertEquals( $title->getFullText(), $hookTitle->getFullText() );
				$this->assertEquals( $user->getName(), $hookUser->getName() );
				$this->assertEquals( $user->getId(), $hookUser->getId() );
				$this->assertEquals( $reason, $hookReason );

				return true;
			} );

		// Create and run the Consequence.
		$consequence = new ApproveMoveConsequence( $moderator, $title, $newTitle, $user, $reason );
		$status = $consequence->run();

		$this->assertTrue( $status->isOK(),
			"ApproveMoveConsequence failed: " . $status->getMessage()->plain() );
		$this->assertTrue( $hookFired, "ApproveMoveConsequence: didn't move anything." );
	}

	/**
	 * Verify that ApproveMoveConsequence fails if the move is invalid (e.g. renaming A to A).
	 * @covers MediaWiki\Moderation\ApproveMoveConsequence
	 */
	public function testApproveInvalidMove() {
		$moderator = self::getTestUser( [ 'moderator', 'automoderated' ] )->getUser();
		$user = self::getTestUser()->getUser();
		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$reason = 'Some reasons why the page was renamed';

		// Precreate the page that will be renamed.
		$this->makeEdit( $title, $moderator );

		// Monitor TitleMoveComplete hook
		$hookFired = false;
		$this->setTemporaryHook( 'TitleMoveComplete', function () use ( &$hookFiredv ) {
			$hookFired = true;
		} );

		// Cause "selfmove" error (trying to rename page into the name it already has).
		$newTitle = $title;

		// Create and run the Consequence.
		$consequence = new ApproveMoveConsequence( $moderator, $title, $newTitle, $user, $reason );
		$status = $consequence->run();

		$this->assertFalse( $status->isOK(),
			"ApproveMoveConsequence succeeded for invalid move." );
		$this->assertTrue( $status->hasMessage( 'selfmove' ),
			"ApproveMoveConsequence didn't return expected 'selfmove' Status." );
		$this->assertFalse( $hookFired, "TitleMoveComplete hook was fired for invalid move." );
	}

	/**
	 * Verify that ApproveMoveConsequence fails if moderator doesn't have "move" permission.
	 * @covers MediaWiki\Moderation\ApproveMoveConsequence
	 */
	public function testModeratorNotAllowedToMove() {
		$moderator = self::getTestUser( [ 'moderator', 'automoderated' ] )->getUser();
		$user = self::getTestUser()->getUser();
		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$newTitle = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) . '-new' );
		$reason = 'Some reasons why the page was renamed';

		// Simulate situation when the moderator is not allowed to rename pages.
		global $wgVersion;
		if ( version_compare( $wgVersion, '1.32.0', '>=' ) ) {
			// MediaWiki 1.32+
			$this->setMwGlobals( 'wgRevokePermissions', [ 'moderator' => [ 'move' => true ] ] );
		} else {
			// MediaWiki 1.31 doesn't have $wgRevokePermissions
			$this->setTemporaryHook( 'UserGetRights', function ( $user, &$rights ) use ( $moderator ) {
				if ( $user->equals( $moderator ) ) {
					$rights = array_diff( $rights, [ 'move' ] );
				}
				return true;
			} );
		}

		// Precreate the page that will be renamed.
		$this->makeEdit( $title, $moderator );

		// Monitor TitleMoveComplete hook
		$hookFired = false;
		$this->setTemporaryHook( 'TitleMoveComplete', function () use ( &$hookFiredv ) {
			$hookFired = true;
		} );

		// Create and run the Consequence.
		$consequence = new ApproveMoveConsequence( $moderator, $title, $newTitle, $user, $reason );
		$status = $consequence->run();

		$this->assertFalse( $status->isOK(),
			"ApproveMoveConsequence succeeded for a moderator who is not allowed to move." );
		$this->assertEquals( 'movenotallowed', $status->getMessage()->getKey(),
			"ApproveMoveConsequence didn't return expected Status." );
		$this->assertFalse( $hookFired, "TitleMoveComplete hook was fired for non-allowed move." );
	}
}
