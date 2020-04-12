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
 * Verifies that batch moderation actions like ApproveAll and RejectAll have expected consequences.
 */

use MediaWiki\Moderation\AddLogEntryConsequence;
use MediaWiki\Moderation\ApproveEditConsequence;
use MediaWiki\Moderation\DeleteRowFromModerationTableConsequence;
use MediaWiki\Moderation\InstallApproveHookConsequence;
use MediaWiki\Moderation\InvalidatePendingTimeCacheConsequence;

require_once __DIR__ . "/autoload.php";

/**
 * @group Database
 */
class BatchActionsHaveConsequencesTest extends ModerationUnitTestCase {
	use ConsequenceTestTrait;
	use ModifyDbRowTestTrait;

	/** @var User */
	protected $authorUser;

	/** @var string[] */
	protected $tablesUsed = [ 'user', 'moderation' ];

	/**
	 * Test consequences of modaction=approveall when approving several changes.
	 * @covers ModerationActionApprove::executeApproveAll
	 */
	public function testApproveAll() {
		$this->authorUser = self::getTestUser()->getUser();
		$this->moderatorUser = self::getTestUser( [ 'moderator', 'automoderated' ] )->getUser();

		// FIXME: need ability to mock multiple consecutive values
		$expectedRevId = rand( 1, 100000 );
		$this->mockApproveHook( $expectedRevId );

		// First, let's queue some edits by the same user for moderation.
		$numberOfEdits = 3;
		$expectedConsequences = [];
		$ids = [];

		for ( $i = 0; $i < $numberOfEdits; $i++ ) {
			$fields = $this->getDefaultFields();
			$ids[] = $modid = $this->makeDbRow( $fields );

			$title = Title::makeTitle( $fields['mod_namespace'], $fields['mod_title'] );

			$expectedConsequences[] = new InstallApproveHookConsequence(
				$title,
				$this->authorUser,
				ModerationNewChange::MOD_TYPE_EDIT,
				[
					'ip' => $fields['mod_ip'],
					'xff' => $fields['mod_header_xff'],
					'ua' => $fields['mod_header_ua'],
					'tags' => $fields['mod_tags'],
					'timestamp' => $fields['mod_timestamp']
				]
			);
			$expectedConsequences[] = new ApproveEditConsequence(
				$this->authorUser,
				$title,
				$fields['mod_text'],
				$fields['mod_comment'],
				(bool)$fields['mod_bot'],
				(bool)$fields['mod_minor'],
				0 // $baseRevId
			);
			$expectedConsequences[] = new AddLogEntryConsequence(
				'approve',
				$this->moderatorUser,
				$title,
				[ 'revid' => $expectedRevId ],
				true // ApproveHook enabled
			);
			$expectedConsequences[] = new DeleteRowFromModerationTableConsequence( $modid );
		}

		// Additional consequences after all edits have been approved: "approveall" LogEntry, etc.
		$expectedConsequences[] = new AddLogEntryConsequence(
			'approveall',
			$this->moderatorUser,
			$this->authorUser->getUserPage(),
			[ '4::count' => $numberOfEdits ]
		);
		$expectedConsequences[] = new InvalidatePendingTimeCacheConsequence();

		// Run modaction=approvell.
		$modid = $this->db->selectField( 'moderation', 'mod_id', '', __METHOD__ );
		$actualConsequences = $this->getConsequences( $modid, 'approveall',
			array_fill( 0, $numberOfEdits, [ ApproveEditConsequence::class, Status::newGood() ] )
		);
		$this->assertConsequencesEqual( $expectedConsequences, $actualConsequences );

		$this->assertSame( $this->result, [
			'approved' => array_fill_keys( $ids, '' ),
			'failed' => []
		] );
		$this->assertEquals( $this->outputText,
			'(moderation-approved-ok: ' . $numberOfEdits . ')' );
	}
}
