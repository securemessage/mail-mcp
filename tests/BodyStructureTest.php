<?php
/**
 * Tests for BODYSTRUCTURE parsing and MIME part number assignment.
 *
 * Regression tests for issue #5: mail_save_attachment fails to fetch
 * parts it correctly enumerates.
 */

use PHPUnit\Framework\TestCase;
use Mail\SocketImapClient;
use Mail\Attachment;

class BodyStructureTest extends TestCase
{
	private \ReflectionMethod $parseMethod;
	private SocketImapClient $client;

	protected function setUp(): void
	{
		$this->client = new SocketImapClient();
		$this->parseMethod = new \ReflectionMethod(SocketImapClient::class, 'parseBodyStructure');
		$this->parseMethod->setAccessible(true);
	}

	/**
	 * Helper to parse a BODYSTRUCTURE string and return attachments.
	 *
	 * @return Attachment[]
	 */
	private function parse(string $structure): array
	{
		return $this->parseMethod->invoke($this->client, $structure);
	}

	/**
	 * Simple single-part text message — no attachments.
	 */
	public function testSimpleTextMessage(): void
	{
		// ("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 100 5 NIL NIL NIL NIL)
		$structure = '("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 100 5 NIL NIL NIL NIL)';
		$attachments = $this->parse($structure);
		$this->assertEmpty($attachments);
	}

	/**
	 * Simple multipart/mixed with one text part and one attachment.
	 * Part numbers: 1 = text/plain, 2 = application/pdf
	 */
	public function testSimpleMultipartWithOneAttachment(): void
	{
		$structure = '("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 100 5 NIL NIL NIL NIL)("APPLICATION" "PDF" ("NAME" "report.pdf") NIL NIL "BASE64" 50000 NIL ("ATTACHMENT" ("FILENAME" "report.pdf")) NIL NIL) "MIXED"';
		$attachments = $this->parse($structure);

		$this->assertCount(1, $attachments);
		$this->assertEquals('report.pdf', $attachments[0]->filename);
		$this->assertEquals('application/pdf', $attachments[0]->contentType);
		$this->assertEquals('2', $attachments[0]->partNumber);
		$this->assertEquals(50000, $attachments[0]->size);
	}

	/**
	 * Regression test for issue #5: nested multipart/mixed with multipart/related.
	 *
	 * multipart/mixed
	 * ├── 1: multipart/related
	 * │   ├── 1.1: multipart/alternative
	 * │   │   ├── 1.1.1: text/plain
	 * │   │   └── 1.1.2: text/html
	 * │   └── 1.2: image/png (image001.png) — inline
	 * └── 2: application/vnd.openxmlformats... (ISDL37.docx)
	 */
	public function testNestedMultipartMixedRelated(): void
	{
		// Regex captures content INSIDE the outermost BODYSTRUCTURE (...)
		// So the multipart/mixed children are at top level here.
		$structure = '('
			. '(("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 100 5 NIL NIL NIL NIL)'
			. '("TEXT" "HTML" ("CHARSET" "UTF-8") NIL NIL "QUOTED-PRINTABLE" 500 20 NIL NIL NIL NIL) "ALTERNATIVE")'
			. '("IMAGE" "PNG" ("NAME" "image001.png") "<image001@cid>" NIL "BASE64" 5000 NIL ("INLINE" ("FILENAME" "image001.png")) NIL NIL) "RELATED")'
			. '("APPLICATION" "VND.OPENXMLFORMATS-OFFICEDOCUMENT.WORDPROCESSINGML.DOCUMENT" ("NAME" "ISDL37 - Disaster Recovery Policy.docx") NIL NIL "BASE64" 100000 NIL ("ATTACHMENT" ("FILENAME" "ISDL37 - Disaster Recovery Policy.docx")) NIL NIL) "MIXED"';

		$attachments = $this->parse($structure);

		$this->assertCount(2, $attachments);

		// image001.png is at part 1.2 (second child of first child of root)
		$this->assertEquals('image001.png', $attachments[0]->filename);
		$this->assertEquals('image/png', $attachments[0]->contentType);
		$this->assertEquals('1.2', $attachments[0]->partNumber);
		$this->assertTrue($attachments[0]->isInline);
		$this->assertEquals('image001@cid', $attachments[0]->contentId);

		// ISDL37.docx is at part 2 (second child of root)
		$this->assertEquals('ISDL37 - Disaster Recovery Policy.docx', $attachments[1]->filename);
		$this->assertEquals('2', $attachments[1]->partNumber);
		$this->assertFalse($attachments[1]->isInline);
	}

	/**
	 * Two file attachments with text — parts 1, 2, 3.
	 */
	public function testMultipleAttachments(): void
	{
		$structure = '("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 100 5 NIL NIL NIL NIL)'
			. '("APPLICATION" "PDF" ("NAME" "doc1.pdf") NIL NIL "BASE64" 10000 NIL ("ATTACHMENT" ("FILENAME" "doc1.pdf")) NIL NIL)'
			. '("IMAGE" "JPEG" ("NAME" "photo.jpg") NIL NIL "BASE64" 20000 NIL ("ATTACHMENT" ("FILENAME" "photo.jpg")) NIL NIL) "MIXED"';

		$attachments = $this->parse($structure);

		$this->assertCount(2, $attachments);
		$this->assertEquals('doc1.pdf', $attachments[0]->filename);
		$this->assertEquals('2', $attachments[0]->partNumber);
		$this->assertEquals('photo.jpg', $attachments[1]->filename);
		$this->assertEquals('3', $attachments[1]->partNumber);
	}

	/**
	 * multipart/alternative with only text parts — no attachments.
	 */
	public function testMultipartAlternativeNoAttachments(): void
	{
		$structure = '("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 100 5 NIL NIL NIL NIL)'
			. '("TEXT" "HTML" ("CHARSET" "UTF-8") NIL NIL "QUOTED-PRINTABLE" 200 10 NIL NIL NIL NIL) "ALTERNATIVE"';

		$attachments = $this->parse($structure);
		$this->assertEmpty($attachments);
	}

	/**
	 * Deeply nested structure: multipart/mixed > multipart/related > multipart/alternative + inline image + attachment.
	 * Tests that part numbers like 1.1.1, 1.1.2, 1.2, 2 are assigned correctly.
	 */
	public function testDeeplyNestedPartNumbers(): void
	{
		// Build a typical Outlook-style email:
		// multipart/mixed
		//   1: multipart/related
		//     1.1: multipart/alternative
		//       1.1.1: text/plain
		//       1.1.2: text/html
		//     1.2: image/png (logo.png, inline)
		//     1.3: image/gif (banner.gif, inline)
		//   2: application/zip (archive.zip, attachment)
		//   3: application/pdf (invoice.pdf, attachment)

		$structure = '('
			. '(("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 100 5 NIL NIL NIL NIL)'
			. '("TEXT" "HTML" ("CHARSET" "UTF-8") NIL NIL "QUOTED-PRINTABLE" 500 20 NIL NIL NIL NIL) "ALTERNATIVE")'
			. '("IMAGE" "PNG" ("NAME" "logo.png") "<logo@cid>" NIL "BASE64" 3000 NIL ("INLINE" ("FILENAME" "logo.png")) NIL NIL)'
			. '("IMAGE" "GIF" ("NAME" "banner.gif") "<banner@cid>" NIL "BASE64" 8000 NIL ("INLINE" ("FILENAME" "banner.gif")) NIL NIL) "RELATED")'
			. '("APPLICATION" "ZIP" ("NAME" "archive.zip") NIL NIL "BASE64" 200000 NIL ("ATTACHMENT" ("FILENAME" "archive.zip")) NIL NIL)'
			. '("APPLICATION" "PDF" ("NAME" "invoice.pdf") NIL NIL "BASE64" 50000 NIL ("ATTACHMENT" ("FILENAME" "invoice.pdf")) NIL NIL) "MIXED"';

		$attachments = $this->parse($structure);

		$this->assertCount(4, $attachments);

		$this->assertEquals('logo.png', $attachments[0]->filename);
		$this->assertEquals('1.2', $attachments[0]->partNumber);
		$this->assertTrue($attachments[0]->isInline);

		$this->assertEquals('banner.gif', $attachments[1]->filename);
		$this->assertEquals('1.3', $attachments[1]->partNumber);
		$this->assertTrue($attachments[1]->isInline);

		$this->assertEquals('archive.zip', $attachments[2]->filename);
		$this->assertEquals('2', $attachments[2]->partNumber);
		$this->assertFalse($attachments[2]->isInline);

		$this->assertEquals('invoice.pdf', $attachments[3]->filename);
		$this->assertEquals('3', $attachments[3]->partNumber);
		$this->assertFalse($attachments[3]->isInline);
	}

	/**
	 * Filename in Content-Type "name" parameter but NOT in Content-Disposition.
	 */
	public function testFilenameFromContentTypeOnly(): void
	{
		$structure = '("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 100 5 NIL NIL NIL NIL)'
			. '("APPLICATION" "OCTET-STREAM" ("NAME" "data.bin") NIL NIL "BASE64" 1000 NIL NIL NIL NIL) "MIXED"';

		$attachments = $this->parse($structure);

		$this->assertCount(1, $attachments);
		$this->assertEquals('data.bin', $attachments[0]->filename);
		$this->assertEquals('2', $attachments[0]->partNumber);
	}

	/**
	 * Filename in Content-Disposition overrides Content-Type "name".
	 */
	public function testFilenameDispositionOverridesContentType(): void
	{
		$structure = '("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 100 5 NIL NIL NIL NIL)'
			. '("APPLICATION" "PDF" ("NAME" "old-name.pdf") NIL NIL "BASE64" 5000 NIL ("ATTACHMENT" ("FILENAME" "correct-name.pdf")) NIL NIL) "MIXED"';

		$attachments = $this->parse($structure);

		$this->assertCount(1, $attachments);
		$this->assertEquals('correct-name.pdf', $attachments[0]->filename);
	}

	/**
	 * Test extractLiteral used by fetchAttachment — verify it finds inlined literal data.
	 */
	public function testExtractLiteralFindsInlinedData(): void
	{
		$method = new \ReflectionMethod(SocketImapClient::class, 'extractLiteral');
		$method->setAccessible(true);

		// Simulate what command() produces: line with {N}\r\n followed by N bytes of data
		$data = 'Hello, World!';
		$line = "* 1 FETCH (BODY[2] {" . strlen($data) . "}\r\n" . $data . ")";

		$result = $method->invoke($this->client, $line);
		$this->assertEquals($data, $result);
	}

	/**
	 * extractLiteral returns null when no literal marker present.
	 */
	public function testExtractLiteralReturnsNullForNoLiteral(): void
	{
		$method = new \ReflectionMethod(SocketImapClient::class, 'extractLiteral');
		$method->setAccessible(true);

		$line = "* 1 FETCH (FLAGS (\\Seen))";
		$result = $method->invoke($this->client, $line);
		$this->assertNull($result);
	}
}
