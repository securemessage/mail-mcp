<?php
/**
 * Tests for Mail\HeaderBlock — lossless raw header parsing
 */

use PHPUnit\Framework\TestCase;
use Mail\HeaderBlock;

class HeaderBlockTest extends TestCase
{
	/**
	 * A real Gmail header block, trimmed to the fields under test.
	 *
	 * Used verbatim because hand-written fixtures tend to be tidier than real
	 * mail: this one folds the DKIM-Signature across six lines, folds the h=
	 * list in the middle of the tag value, indents continuations with a mix of
	 * spaces, and carries two Received fields.
	 */
	private const GMAIL = "Received: from mail-sor-f41.google.com (mail-sor-f41.google.com [209.85.220.41])\r\n"
		. "\tby mx.morante.net (Postfix) with ESMTPS id 4c1Xy2\r\n"
		. "\tfor <justin@morante.net>; Tue, 28 Jul 2026 20:49:24 -0400 (EDT)\r\n"
		. "Received: by 2002:a05:6a20:e18b with SMTP id abc;\r\n"
		. "        Tue, 28 Jul 2026 17:49:24 -0700 (PDT)\r\n"
		. "DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed;\r\n"
		. "        d=gmail.com; s=20251104; t=1785286164; x=1785890964; darn=morante.net;\r\n"
		. "        h=content-type:to:subject:message-id:date:from:reply-to:mime-version\r\n"
		. "         :from:to:cc:subject:date:message-id:reply-to:content-type;\r\n"
		. "        bh=RB2AQ0D4ARDFvoQp2Bgi8OrVisGN68KqDmvHo/VHKYo=;\r\n"
		. "        b=iUaeIuhmM0NCOi7FaFQv6x4D+2O9quF9sq3XJGsqEhu3HCr9a+8q0NBPGvKpWw5iXS\r\n"
		. "         Pfhg==\r\n"
		. "Subject: Test\r\n"
		. "From: Daniel Morante <tuaris@gmail.com>";

	public function testSplitKeepsFoldedFieldsWhole(): void
	{
		$fields = HeaderBlock::split(self::GMAIL);

		// Five fields, not the fifteen lines they occupy.
		$this->assertCount(5, $fields);

		$this->assertStringStartsWith('Received: from mail-sor-f41', $fields[0]);
		$this->assertStringStartsWith('Received: by 2002:', $fields[1]);
		$this->assertStringStartsWith('DKIM-Signature: v=1;', $fields[2]);
		$this->assertSame('Subject: Test', $fields[3]);
		$this->assertSame('From: Daniel Morante <tuaris@gmail.com>', $fields[4]);
	}

	public function testFoldingIsPreservedVerbatim(): void
	{
		$fields = HeaderBlock::split(self::GMAIL);
		$dkim = $fields[2];

		// The CRLFs and the exact continuation indentation survive. A DKIM
		// verifier canonicalizes these octets, so losing them changes the hash.
		$this->assertStringContainsString("c=relaxed/relaxed;\r\n        d=gmail.com", $dkim);

		// The h= list is folded mid-value, with the continuation beginning at a
		// colon. Unfolding must not be done here.
		$this->assertStringContainsString("mime-version\r\n         :from:to:cc", $dkim);
	}

	public function testRepeatedFieldsAreAllReturned(): void
	{
		$received = HeaderBlock::select(self::GMAIL, ['Received']);

		// Both hops, in order. Collapsing to one would misrepresent the path the
		// message actually took.
		$this->assertCount(2, $received);
		$this->assertStringContainsString('mail-sor-f41', $received[0]);
		$this->assertStringContainsString('2002:a05', $received[1]);
	}

	public function testSelectIsCaseInsensitiveOnName(): void
	{
		$this->assertCount(1, HeaderBlock::select(self::GMAIL, ['dkim-signature']));
		$this->assertCount(1, HeaderBlock::select(self::GMAIL, ['DKIM-Signature']));
		$this->assertCount(1, HeaderBlock::select(self::GMAIL, ['DkIm-SiGnAtUrE']));
	}

	public function testSelectReturnsEmptyForAbsentField(): void
	{
		$this->assertSame([], HeaderBlock::select(self::GMAIL, ['ARC-Seal']));
	}

	public function testNameOfHandlesOddInput(): void
	{
		$this->assertSame('subject', HeaderBlock::nameOf('Subject: hi'));
		// No colon is not a field.
		$this->assertSame('', HeaderBlock::nameOf('garbage line'));
		// Whitespace before the colon is not part of the name.
		$this->assertSame('subject', HeaderBlock::nameOf('Subject : hi'));
		// An empty value is still a field.
		$this->assertSame('x-empty', HeaderBlock::nameOf('X-Empty:'));
	}

	public function testSplitOnEmptyBlock(): void
	{
		$this->assertSame([], HeaderBlock::split(''));
		$this->assertSame([], HeaderBlock::split("\r\n"));
	}

	public function testBareLfIsAcceptedAsALineBreak(): void
	{
		// Some stores and test fixtures carry LF rather than CRLF.
		$raw = "Subject: a\nFrom: b\n\tcontinued";
		$fields = HeaderBlock::split($raw);

		$this->assertCount(2, $fields);
		$this->assertSame("From: b\n\tcontinued", $fields[1]);
	}
}
