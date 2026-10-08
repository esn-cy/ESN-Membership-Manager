<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Object;

use Drupal\esn_membership_manager\Object\Status;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Status object.
 *
 * @covers \Drupal\esn_membership_manager\Object\Status
 * @group esn_membership_manager
 */
class StatusTest extends TestCase
{
    /**
     * Tests default constructor arguments when only status is passed.
     */
    public function testConstructorWithDefaultArguments(): void
    {
        $status = new Status('Approved');

        $this->assertSame('Approved', $status->status);
        $this->assertSame('', $status->category);
        $this->assertSame('', $status->issue);
    }

    /**
     * Tests constructor when status and category are provided.
     */
    public function testConstructorWithCategoryOnly(): void
    {
        $status = new Status('Pending', 'Identity');

        $this->assertSame('Pending', $status->status);
        $this->assertSame('Identity', $status->category);
        $this->assertSame('', $status->issue);
    }

    /**
     * Tests constructor when all three arguments are provided.
     */
    public function testConstructorWithAllArguments(): void
    {
        $status = new Status('Rejected', 'Photo', 'TooBlurry');

        $this->assertSame('Rejected', $status->status);
        $this->assertSame('Photo', $status->category);
        $this->assertSame('TooBlurry', $status->issue);
    }

    /**
     * Tests that public properties can be directly modified.
     */
    public function testPropertyMutation(): void
    {
        $status = new Status('Pending');
        $status->status = 'Paid';
        $status->category = 'Manual';
        $status->issue = 'ReceiptMissing';

        $this->assertSame('Paid', $status->status);
        $this->assertSame('Manual', $status->category);
        $this->assertSame('ReceiptMissing', $status->issue);
    }

    /**
     * Tests that clearIssue() resets category and issue to empty strings and returns self.
     */
    public function testClearIssueResetsCategoryAndIssue(): void
    {
        $status = new Status('Rejected', 'Identity', 'ExpiredDocument');

        $result = $status->clearIssue();

        $this->assertSame($status, $result, 'clearIssue() should return the same instance (fluent).');
        $this->assertSame('Rejected', $status->status);
        $this->assertSame('', $status->category);
        $this->assertSame('', $status->issue);
    }

    /**
     * Tests clearIssue() when category and issue are already empty.
     */
    public function testClearIssueWhenAlreadyEmpty(): void
    {
        $status = new Status('Approved');

        $result = $status->clearIssue();

        $this->assertSame($status, $result);
        $this->assertSame('Approved', $status->status);
        $this->assertSame('', $status->category);
        $this->assertSame('', $status->issue);
    }

    /**
     * Tests toString() when both category and issue are empty.
     */
    public function testToStringWithoutCategoryAndIssueReturnsStatusOnly(): void
    {
        $status = new Status('Approved');
        $this->assertSame('Approved', $status->toString());

        $statusPending = new Status('Pending', '', '');
        $this->assertSame('Pending', $statusPending->toString());
    }

    /**
     * Tests toString() when both category and issue are set.
     */
    public function testToStringWithCategoryAndIssueFormatsHyphenated(): void
    {
        $status = new Status('Pending', 'Name', 'Changed');
        $this->assertSame('Pending-Name-Changed', $status->toString());
    }

    /**
     * Tests toString() when category is set but issue is empty.
     */
    public function testToStringWithCategoryOnly(): void
    {
        $status = new Status('Rejected', 'Photo', '');
        $this->assertSame('Rejected-Photo-', $status->toString());
    }

    /**
     * Tests toString() when category is empty but issue is set.
     */
    public function testToStringWithIssueOnly(): void
    {
        $status = new Status('Rejected', '', 'DocumentExpired');
        $this->assertSame('Rejected--DocumentExpired', $status->toString());
    }

    /**
     * Tests toString() after calling clearIssue().
     */
    public function testToStringAfterClearIssue(): void
    {
        $status = new Status('Rejected', 'Photo', 'TooBlurry');
        $this->assertSame('Rejected-Photo-TooBlurry', $status->toString());

        $status->clearIssue();
        $this->assertSame('Rejected', $status->toString());
    }

    /**
     * Tests edge case with empty status string.
     */
    public function testToStringWithEmptyStatus(): void
    {
        $status = new Status('');
        $this->assertSame('', $status->toString());

        $statusWithCategory = new Status('', 'Cat', 'Iss');
        $this->assertSame('-Cat-Iss', $statusWithCategory->toString());
    }
}
