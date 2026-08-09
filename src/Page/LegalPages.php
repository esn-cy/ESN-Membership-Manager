<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Page;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\omnia\Config\OmniaSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

class LegalPages extends ControllerBase
{
    protected MembershipSettings $membershipSettings;
    protected OmniaSettings $omniaSettings;

    public function __construct(
        ConfigFactoryInterface $configFactory,
    )
    {
        $this->membershipSettings = new MembershipSettings($configFactory);
        $this->omniaSettings = new OmniaSettings($configFactory);
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        return new static(
            $configFactory,
        );
    }

    public function tosPage(): array
    {
        $content = "
      <h1>Terms of Service</h1>
      <p><b>Last Updated: </b>18/05/2026</p>
      <p>Welcome to the ESN Membership Manager platform. By applying for a membership, you agree to be bound by these Terms of Service.</p>
      <h3>1. Acceptance of Terms</h3>
      <p>By submitting an application, purchasing a membership, or downloading a digital pass through this platform, you agree to comply with and be bound by these Terms.</p>
      <h3>2. Services Provided</h3>
      <p>This platform facilitates the management of Erasmus Student Network (ESN) memberships. Our services include:</p>
      <ul>
        <li>Processing membership applications and validating ESNcard numbers.</li>
        <li>Processing membership fees via secure payment gateways.</li>
        <li>Issuing digital membership passes compatible with third-party digital wallets.</li>
        <li>Authenticating membership status for external event and ticketing platforms.</li>
        <li>Verifying status using external providers.</li>
        <li>Verifying ID using external providers.</li>
      </ul>
      <h3>3. User Responsibilities</h3>
      <p>You agree to provide accurate, current, and complete information during the application process. You are responsible for maintaining the confidentiality of any authentication links or digital passes issued to you. Your submission of personal data, identity documents, and face photos is governed by our Privacy Policy, which details our strict data retention and automated deletion procedures.</p>
      <h3>4. Payments and Refunds</h3>
      <p>All online payments are processed securely through Stripe. We do not store your credit card information. Refund policies are subject to {$this->omniaSettings->getOrganisationName()}'s specific bylaws.</p>
      <h3>5. Third-Party Integrations</h3>
      <p>Our service relies on third-party platforms to deliver full functionality, including Stripe (payments), Didit (identity verification), Weeztix (ticketing integration), and Google Sheets (administrative logging). Furthermore, we issue passes compatible with Apple Wallet and Google Wallet. Your use of these specific features is also subject to the respective terms of service and privacy policies of those external providers.</p>
      <h3>6. Termination or Revocation</h3>
      <p>We reserve the right to deny, suspend, or revoke your membership, digital passes, or ESNcard at our sole discretion, including in cases of fraudulent application data or violation of ESN guidelines. Revoked passes will be disabled and invalidated across all connected platforms.</p>
      <h3>7. Limitation of Liability</h3>
      <p>The platform is provided on an 'as is' basis. We are not liable for temporary service interruptions, delays in pass issuance, or failures caused by our third-party payment, verification, or digital wallet partners.</p>
      <h3>8. Modifications</h3>
      <p>We reserve the right to modify these Terms at any time. Continued use of the platform constitutes your consent to such changes.</p>
    ";

        return [
            '#type' => 'markup',
            '#markup' => $content,
        ];
    }

    public function privacyPage(): array
    {
        $content = "
      <h1>Privacy Policy</h1>
      <p><b>Last Updated: </b>18/05/2026</p>
      <p>This Privacy Policy explains how ESN Membership Manager (\"we,\" \"us,\" or \"our\"), operated by {$this->omniaSettings->getOrganisationName()}, collects, uses, shares, and protects your personal information when you use our platform to apply for, manage, and use your ESNcard and {$this->membershipSettings->getPassName()}.</p>
      <h3>1. Information We Collect</h3>
      <p>To provide you with our services, we collect the following personal information during the application process:</p>
      <ul>
        <li><b>Identity Data: </b>Name, surname, date of birth, and nationality.</li>
        <li><b>Contact Data: </b>Email address.</li>
        <li><b>Academic & Mobility Data: </b>Host institution, ESN section, and mobility status (e.g., Erasmus Student, ESN Volunteer).</li>
        <li><b>Media & Documents: </b>Scanned identity/mobility proof documents (in case of manual verification) and a face photo (in case of an ESNcard).</li>
      </ul>
      <h3>2. How We Use Your Information</h3>
      <p>We use your data strictly to:</p>
      <ul>
        <li>Verify your eligibility for the ESNcard or {$this->membershipSettings->getPassName()}.</li>
        <li>Issue and manage your membership, including physical ESNcards, digital {$this->membershipSettings->getPassName()}, and Guest Passes.</li>
        <li>Process payments for your membership.</li>
        <li>Prevent fraud and ensure compliance with ESN guidelines.</li>
      </ul>
      <h3>3. Data Retention and Deletion (GDPR Compliance)</h3>
      <p>We employ strict, automated data retention policies to ensure we do not hold your personal data longer than necessary. Our system automatically processes data deletion according to the following schedule:</p>
      <ul>
        <li><b>Identity Documents and Proofs: </b>Any uploaded ID scans and proofs of mobility are permanently deleted from our servers 14 days after your application is either approved or rejected.</li>
        <li><b>Face Photos: </b>Face photos are permanently deleted 1 year (365 days) after your payment date (if you applied for an ESNcard).</li>
        <li><b>Account Anonymization: </b>After 1 year, your personal database record is completely anonymized. Your name and surname are replaced with \"Anonymized,\" your date of birth is reset to the 1st of January of your birthyear, and your email address is permanently obfuscated.</li>
        <li><b>Associated Records: </b>At the 1-year mark, any Apple Wallet registrations, authentication codes, and guest passes tied to your account are permanently erased.</li>
      </ul>
      <h3>4. Third-Party Integrations and Data Sharing</h3>
      <p>To operate ESN Membership Manager effectively, we integrate with trusted third-party services. We only share the minimum data required for these services to function:</p>
      <ul>
        <li><b>Stripe (Payments): </b>If your application includes and ESNcard, a Stripe payment link is added to your account. We share your application ID with Stripe to link your payment to your application. After one year, your active payment links are disabled.</li>
        <li><b>Weeztix (Ticketing): </b>When you are assigned an ESNcard, your card number is securely transmitted to Weeztix to act as an active coupon code for event discounts.</li>
        <li><b>Google Services:</b>
            <li><b>Google Sheets: </b>We maintain financial and issuance logs via Google Sheets. We log the date, your full name, card number, host institution, nationality, payment method, and amount paid.</li>
            <li><b>Google Wallet: </b>If you add your pass to Google Wallet, we transmit your name, photo, date of birth, nationality, and mobility details to Google to generate the secure digital object.</li>
        </li>
        <li><b>Apple Wallet: </b>f you add your pass to an iOS device, similar pass data (name, nationality, DOB, photo) is formatted for Apple Wallet. We use the Apple Push Notification service (APNs) to send background updates to your passes if your membership details change.</li>
      </ul>
      <h3>5. Your Data Protection Rights</h3>
      <p>Under the General Data Protection Regulation (GDPR), you have the right to:</p>
      <ul>
      <li><b>Access: </b>Request a copy of the personal data we hold about you.</li>
      <li><b>Rectification: </b>Request correction of inaccurate personal data.</li>
      <li><b>Erasure (Right to be Forgotten): </b>Request immediate deletion of your data before our automated 1-year retention period, provided there is no legal obligation to retain it.</li>
      <li><b>Restriction & Objection: </b>Object to or restrict the processing of your data.</li>
      </ul>
      <h3>6. Contact Us</h3>
      <p>If you have any questions about this Privacy Policy, how your data is handled, or if you wish to exercise your data rights, please contact the Web Projects Administrator of {$this->omniaSettings->getOrganisationName()}.</p>
    ";

        return [
            '#type' => 'markup',
            '#markup' => $content,
        ];
    }
}