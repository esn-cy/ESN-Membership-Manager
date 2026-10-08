<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Utility;

use Drupal\esn_membership_manager\Object\Status;
use Drupal\esn_membership_manager\Utility\ApprovalStatuses;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ApprovalStatuses utility.
 *
 * @covers       \Drupal\esn_membership_manager\Utility\ApprovalStatuses
 * @uses         \Drupal\esn_membership_manager\Object\Status
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class ApprovalStatusesTest extends TestCase
{
    /**
     * Tests getStatuses() with a single simple status.
     */
    public function testGetStatusesSingleSimpleStatus(): void
    {
        $statuses = ApprovalStatuses::getStatuses('Pending');

        $this->assertCount(1, $statuses);
        $this->assertInstanceOf(Status::class, $statuses[0]);
        $this->assertSame('Pending', $statuses[0]->status);
        $this->assertSame('', $statuses[0]->category);
        $this->assertSame('', $statuses[0]->issue);
    }

    /**
     * Tests getStatuses() with a single compound status (with category and issue).
     */
    public function testGetStatusesSingleCompoundStatus(): void
    {
        $statuses = ApprovalStatuses::getStatuses('Pending-Name-Changed');

        $this->assertCount(1, $statuses);
        $this->assertSame('Pending', $statuses[0]->status);
        $this->assertSame('Name', $statuses[0]->category);
        $this->assertSame('Changed', $statuses[0]->issue);
    }

    /**
     * Tests getStatuses() with multiple statuses separated by slashes.
     */
    public function testGetStatusesMultipleStatuses(): void
    {
        $raw = 'Pending-Name-Changed / Rejected-Photo-TooBlurry / Approved';
        $statuses = ApprovalStatuses::getStatuses($raw);

        $this->assertCount(3, $statuses);
        $this->assertSame('Pending', $statuses[0]->status);
        $this->assertSame('Name', $statuses[0]->category);
        $this->assertSame('Changed', $statuses[0]->issue);

        $this->assertSame('Rejected', $statuses[1]->status);
        $this->assertSame('Photo', $statuses[1]->category);
        $this->assertSame('TooBlurry', $statuses[1]->issue);

        $this->assertSame('Approved', $statuses[2]->status);
        $this->assertSame('', $statuses[2]->category);
        $this->assertSame('', $statuses[2]->issue);
    }

    /**
     * Tests getDominantStatus() when rawStatus contains no slashes.
     */
    public function testGetDominantStatusWithoutSlash(): void
    {
        // Simple status
        $status = ApprovalStatuses::getDominantStatus('Approved');
        $this->assertSame('Approved', $status->status);

        // Compound status: extracts leading alpha characters
        $compound = ApprovalStatuses::getDominantStatus('Pending-Identity-Expired');
        $this->assertSame('Pending', $compound->status);

        // Fallback when no leading alpha chars match
        $nonAlpha = ApprovalStatuses::getDominantStatus('123');
        $this->assertSame('123', $nonAlpha->status);
    }

    /**
     * Tests getDominantStatus() priority when Negative status is present.
     */
    public function testGetDominantStatusNegativeDominates(): void
    {
        // Rejected dominates even if positive or pending statuses are present, and clears issue
        $raw = 'Approved/Rejected-Photo-Blurry/Paid';
        $dominant = ApprovalStatuses::getDominantStatus($raw);

        $this->assertSame('Rejected', $dominant->status);
        $this->assertSame('', $dominant->category);
        $this->assertSame('', $dominant->issue);
    }

    /**
     * Tests getDominantStatus() priority when Blacklisted is present.
     */
    public function testGetDominantStatusBlacklistedDominatesOverPendingAndPositive(): void
    {
        $raw = 'Approved/Blacklisted/Pending';
        $dominant = ApprovalStatuses::getDominantStatus($raw);

        $this->assertSame(ApprovalStatuses::Blacklisted, $dominant->status);
    }

    /**
     * Tests getDominantStatus() priority when Pending is present.
     */
    public function testGetDominantStatusPendingDominatesOverPositive(): void
    {
        $raw = 'Approved/Pending/Paid';
        $dominant = ApprovalStatuses::getDominantStatus($raw);

        $this->assertSame(ApprovalStatuses::Pending, $dominant->status);
    }

    /**
     * Tests getDominantStatus() positive statuses hierarchy: Approved < Paid < Issued < Delivered.
     */
    public function testGetDominantStatusPositiveHierarchy(): void
    {
        $raw1 = 'Approved/Paid';
        $this->assertSame(ApprovalStatuses::Paid, ApprovalStatuses::getDominantStatus($raw1)->status);

        $raw2 = 'Paid/Approved/Issued';
        $this->assertSame(ApprovalStatuses::Issued, ApprovalStatuses::getDominantStatus($raw2)->status);

        $raw3 = 'Delivered/Issued/Paid/Approved';
        $this->assertSame(ApprovalStatuses::Delivered, ApprovalStatuses::getDominantStatus($raw3)->status);
    }

    /**
     * Tests getDominantStatus() fallback when no known statuses match.
     */
    public function testGetDominantStatusFallback(): void
    {
        $raw = 'CustomStatusAlpha/CustomStatusBeta';
        $dominant = ApprovalStatuses::getDominantStatus($raw);

        $this->assertSame('CustomStatusAlpha', $dominant->status);
    }

    /**
     * Tests canAddStatus() when adding Positive status 'Approved'.
     */
    public function testCanAddStatusApproved(): void
    {
        // Pending application -> can be approved
        $this->assertNull(ApprovalStatuses::canAddStatus('Pending', ApprovalStatuses::Approved));

        // Already approved -> issue
        $issueApproved = ApprovalStatuses::canAddStatus('Approved', ApprovalStatuses::Approved);
        $this->assertSame('This application already been approved or rejected.', $issueApproved);

        // Already rejected -> issue
        $issueRejected = ApprovalStatuses::canAddStatus('Rejected', ApprovalStatuses::Approved);
        $this->assertSame('This application already been approved or rejected.', $issueRejected);
    }

    /**
     * Tests canAddStatus() when adding Positive status 'Paid'.
     */
    public function testCanAddStatusPaid(): void
    {
        // Coming from Approved -> allowed
        $this->assertNull(ApprovalStatuses::canAddStatus('Approved', ApprovalStatuses::Paid));

        // Already Paid -> duplicate
        $issueAlreadyPaid = ApprovalStatuses::canAddStatus('Paid', ApprovalStatuses::Paid);
        $this->assertSame('This status has already been applied.', $issueAlreadyPaid);

        // Coming directly from Pending (skipping Approved) -> out of order
        $issueOutOfOrder = ApprovalStatuses::canAddStatus('Pending', ApprovalStatuses::Paid);
        $this->assertSame('This status has been applied out of order.', $issueOutOfOrder);
    }

    /**
     * Tests canAddStatus() when adding Positive status 'Issued'.
     */
    public function testCanAddStatusIssued(): void
    {
        // Coming from Paid -> allowed
        $this->assertNull(ApprovalStatuses::canAddStatus('Paid', ApprovalStatuses::Issued));

        // Coming from Approved (skipping Paid) -> out of order
        $issue = ApprovalStatuses::canAddStatus('Approved', ApprovalStatuses::Issued);
        $this->assertSame('This status has been applied out of order.', $issue);
    }

    /**
     * Tests canAddStatus() when adding Positive status 'Delivered'.
     */
    public function testCanAddStatusDelivered(): void
    {
        // Coming from Issued -> allowed
        $this->assertNull(ApprovalStatuses::canAddStatus('Issued', ApprovalStatuses::Delivered));
    }

    /**
     * Tests canAddStatus() when adding Negative status 'Rejected'.
     */
    public function testCanAddStatusRejected(): void
    {
        // Pending application -> can be rejected
        $this->assertNull(ApprovalStatuses::canAddStatus('Pending', ApprovalStatuses::Rejected));

        // Approved application -> cannot reject
        $issueApproved = ApprovalStatuses::canAddStatus('Approved', ApprovalStatuses::Rejected);
        $this->assertSame('This application already been approved or rejected.', $issueApproved);

        // Already rejected application -> cannot reject again
        $issueRejected = ApprovalStatuses::canAddStatus('Rejected', ApprovalStatuses::Rejected);
        $this->assertSame('This application already been approved or rejected.', $issueRejected);
    }

    /**
     * Tests canAddStatus() when adding Auxiliary status 'Blacklisted'.
     */
    public function testCanAddStatusBlacklisted(): void
    {
        // Cannot blacklist a pending application
        $issuePending = ApprovalStatuses::canAddStatus('Pending', ApprovalStatuses::Blacklisted);
        $this->assertSame('This status cannot be applied to a pending application.', $issuePending);

        // Can blacklist an approved or paid application
        $this->assertNull(ApprovalStatuses::canAddStatus('Approved', ApprovalStatuses::Blacklisted));
        $this->assertNull(ApprovalStatuses::canAddStatus('Paid', ApprovalStatuses::Blacklisted));
    }

    /**
     * Tests canAddStatus() with custom unmanaged status returns null.
     */
    public function testCanAddStatusCustomStatusReturnsNull(): void
    {
        $this->assertNull(ApprovalStatuses::canAddStatus('Pending', 'UnknownStatus'));
    }

    /**
     * Tests addStatus() concatenates with slash.
     */
    public function testAddStatus(): void
    {
        $this->assertSame('Pending/Approved', ApprovalStatuses::addStatus('Pending', 'Approved'));
        $this->assertSame('Pending/Approved/Paid', ApprovalStatuses::addStatus('Pending/Approved', 'Paid'));
    }

    /**
     * Tests removeStatus() removes specified substring and cleans up delimiters.
     */
    public function testRemoveStatus(): void
    {
        // Removing from middle
        $this->assertSame('Pending/Paid', ApprovalStatuses::removeStatus('Pending/Approved/Paid', 'Approved'));

        // Removing from beginning
        $this->assertSame('Paid', ApprovalStatuses::removeStatus('Approved/Paid', 'Approved'));

        // Removing the only status
        $this->assertSame('', ApprovalStatuses::removeStatus('Pending', 'Pending'));
    }
}
