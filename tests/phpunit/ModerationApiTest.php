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
 * @file
 * @brief Verifies that modactions work via api.php?action=moderation.
 */

require_once __DIR__ . "/framework/ModerationTestsuite.php";

/**
 * @covers ApiModeration
 */
class ModerationApiTest extends MediaWikiTestCase {

	/**
	 * @brief Checks return value of api.php?action=moderation&modaction=...
	 * @note Consequences of actions are checked by other tests (e.g. ModerationApproveTest).
	 * @testWith	[ "approve", { "moderation": { "approved": [ "{{ID}}" ] } } ]
				[ "approveall", { "moderation": { "approved": { "{{ID}}": "" }, "failed": [] } } ]
				[ "reject", { "moderation": { "rejected-count":1 } } ]
				[ "rejectall", { "moderation": { "rejected-count":1 } } ]
				[ "block", { "moderation": {"action": "block", "username": "{{AUTHOR}}", "success": "" } } ]
				[ "unblock", { "moderation": {"action": "unblock", "username": "{{AUTHOR}}" } } ]
				[ "show", { "moderation": { "diff-html": "{{DIFF}}", "title": "{{TITLE}}" } } ]
	*/
	public function testModerationApi( $action, array $expectedResult ) {
		/* Prepare a fake moderation entry */
		$t = new ModerationTestsuite;
		$entry = $t->getSampleEntry();

		/* Replace {{ID}} and {{AUTHOR}} in $expectedResult */
		$expectedResult = FormatJson::decode(
			str_replace(
				[ '{{ID}}', '{{AUTHOR}}', '{{TITLE}}' ],
				[ $entry->id, $entry->user, $entry->title ],
				FormatJson::encode( $expectedResult )
			),
			true
		);

		$ret = $t->query( [
			'action' => 'moderation',
			'modid' => $entry->id,
			'modaction' => $action,
			'token' => null
		] );

		if ( $action == 'show' && isset( $ret['moderation']['diff-html'] ) ) {
			/* Correctness of diff is already checked in ModerationShowTest,
				we don't want to duplicate the same check.
			*/
			$ret['moderation']['diff-html'] = '{{DIFF}}';
		}

		$this->assertEquals( $ret, $expectedResult );
	}
}
