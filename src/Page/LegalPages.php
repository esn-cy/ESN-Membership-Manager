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
      <p>Our service relies on third-party platforms to deliver full functionality, including Stripe (payments), Didit (identity verification), ESN Accounts managed by ESN AISBL (status verification), Weeztix (ticketing integration), and Google Sheets (administrative logging). Furthermore, we issue passes compatible with Apple Wallet and Google Wallet. Your use of these specific features is also subject to the respective terms of service and privacy policies of those external providers.</p>
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
        $orgName = htmlspecialchars($this->omniaSettings->getOrganisationName() ?? '');
        $passName = htmlspecialchars($this->membershipSettings->getPassName());
        $contactEmail = htmlspecialchars($this->membershipSettings->getAdminEmailAddress() ?? '');

        $content = <<<HTML
            <h1>Privacy Policy</h1>
            <p><b>Last Updated: </b>26/10/2026</p>
            <p>This Privacy Policy explains how ESN Membership Manager ("we," "us," or "our"), operated by <strong>$orgName</strong> as the Data Controller under the General Data Protection Regulation (GDPR), collects, uses, shares, and protects your personal information when you use our platform to apply for, manage, and use your ESNcard, $passName, and associated guest passes.</p>

            <h3>1. Information We Collect</h3>
            <p>We collect and process the minimum amount of personal data necessary to verify your eligibility, issue your membership credentials, and facilitate event participation:</p>
            <ul>
                <li><b>Identity Data: </b>Full legal name, surname, date of birth, and nationality.</li>
                <li><b>Contact Data: </b>Email address (used for passwordless one-time code authentication and service notifications).</li>
                <li><b>Academic & Mobility Data: </b>Host institution/university, local ESN section, and mobility status/programme (e.g., Erasmus+ Studies, Traineeship, European Solidarity Corps, International Full Degree).</li>
                <li><b>Verification Media & Documents: </b>Scanned copies of government-issued identity documents (passport or national ID card), mobility proof documents (e.g., Learning Agreement, Traineeship Certificate, Acceptance Letter, or Certificate of Studies), and a passport-style portrait photograph (required for physical ESNcard printing and digital wallet representation).</li>
                <li><b>Technical & Session Data: </b>IP addresses, browser user-agent strings, and session/CSRF tokens strictly required to authenticate your requests and protect against unauthorised access.</li>
            </ul>

            <h3>2. Legal Bases for Processing (GDPR Article 6 & Article 9)</h3>
            <p>We process your personal information under the following lawful bases:</p>
            <ul>
                <li><b>Performance of a Contract (Art. 6(1)(b) GDPR): </b>Processing is necessary to evaluate your application, issue your $passName or ESNcard, process payment, generate wallet passes, and grant you access to member-only events and ticketing.</li>
                <li><b>Explicit Consent (Art. 6(1)(a) & Art. 9(2)(a) GDPR): </b>Where applicable, you provide explicit consent when uploading identity documents, face photographs, or initiating automated identity verification via third-party providers.</li>
                <li><b>Legitimate Interests (Art. 6(1)(f) GDPR): </b>To maintain platform security, prevent fraud or double-issuance of subsidised mobility cards, and ensure safe, organised event admissions.</li>
                <li><b>Legal Obligation (Art. 6(1)(c) GDPR): </b>To comply with applicable non-profit financial, tax, and auditing requirements relating to membership fee transactions.</li>
            </ul>

            <h3>3. How We Use Your Information</h3>
            <p>Your data is used strictly for the following purposes:</p>
            <ul>
                <li>Verifying your eligibility for the $passName or official ESNcard under Erasmus Student Network AISBL statutes.</li>
                <li>Generating and issuing digital passes for mobile wallets (Apple Wallet, Google Wallet) and producing physical ESNcards.</li>
                <li>Administering temporary Guest Passes for visiting friends and family.</li>
                <li>Validating active membership status at event entrances and integrating with our event ticketing platforms.</li>
                <li>Processing membership fees securely via our payment gateway.</li>
            </ul>
            
            <h3>4. Third-Party Integrations and Data Processors</h3>
            <p>We share data only with trusted third-party service providers acting as data processors or independent controllers where necessary to deliver core platform services:</p>
            <ul>
                <li><b>Didit (Identity Verification): </b>If you choose online identity verification, you are redirected to Didit (Didit Identity Verification). Didit processes your ID document and a live facial capture to confirm identity and prevent duplicate applications. Didit processes this data under strict security standards in compliance with GDPR.</li>
                <li><b>ESN Accounts (ESN AISBL): </b>For members or volunteers authenticating via Single Sign-On, credentials and roles are verified against the central identity system managed by Erasmus Student Network AISBL in Brussels, Belgium.</li>
                <li><b>Stripe (Payment Processing): </b>All online payments are processed through Stripe Payments Europe, Ltd. We transmit your application identifier to reconcile payments. We do not collect, process, or store credit or debit card numbers on our servers.</li>
                <li><b>Weeztix (Event Ticketing): </b>When an ESNcard or $passName is issued, your card identifier is shared with Weeztix to activate membership pricing coupons for event tickets.</li>
                <li><b>Google Services:</b>
                  <ul>
                    <li><b>Google Wallet: </b>If you choose to add your pass to Google Wallet, we transmit your name, photo, date of birth, nationality, and pass identifiers to Google LLC / Google Ireland Ltd. via the Google Wallet API to generate the secure digital pass.</li>
                    <li><b>Google Sheets: </b>We maintain administrative and financial auditing logs (date, name, card number, institution, nationality, payment method, amount paid) on access-controlled Google Sheets.</li>
                  </ul>
                </li>
                <li><b>Apple Wallet & APNs: </b>If you add your pass to an iOS device, pass data is formatted into an encrypted Apple `.pkpass` file. Device identifiers and Apple Push Notification service (APNs) tokens are retained solely to push real-time status and expiry updates to your pass.</li>
            </ul>

            <h3>5. International Data Transfers</h3>
            <p>Certain third-party service providers (such as Google, Apple, or Stripe) may process or store data on servers located outside the European Economic Area (EEA). In such cases, we ensure that appropriate safeguards are in place—such as European Commission Standard Contractual Clauses (SCCs), adequacy decisions, or equivalent legally recognised frameworks—to ensure your personal data receives a level of protection essentially equivalent to that guaranteed within the European Union.</p>
            
            <h3>6. Data Retention and Automated Deletion (GDPR Compliance)</h3>
            <p>We employ automated data minimisation and purge schedules to ensure personal data is never retained longer than necessary:</p>
            <ul>
                <li><b>Identity Documents and Mobility Proofs: </b>All uploaded ID scans, passports, and mobility proof documents are permanently and irrevocably deleted from our servers <strong>14 days</strong> after your application is either approved or rejected.</li>
                <li><b>Face Photographs: </b>Passport-style portrait photos are permanently deleted <strong>1 year (365 days)</strong> after your payment/issuance date.</li>
                <li><b>Account Anonymization: </b>Exactly 1 year after issuance, your personal database record is completely and irreversibly anonymised. Your name is set to "Anonymized," your date of birth is truncated to the 1st of January of your birth year, and your email address is permanently obfuscated.</li>
                <li><b>Associated Tokens: </b>At the 1-year mark, all Apple Wallet device registrations, authentication passcodes, and guest pass records tied to your account are permanently erased.</li>
            </ul>

            <h3>7. Cookies and Tracking Technologies</h3>
            <p>Our platform does not use advertising, marketing, or tracking cookies. We use only strictly necessary, functional cookies and session tokens required to maintain your authenticated session and provide Cross-Site Request Forgery (CSRF) protection. You can disable cookies in your browser settings, but doing so may prevent you from logging into the platform.</p>

            <h3>8. Your Data Protection Rights</h3>
            <p>Under the General Data Protection Regulation (GDPR), you possess the following rights regarding your personal data:</p>
            <ul>
                <li><b>Right of Access (Art. 15): </b>You can request confirmation of whether we process your data and obtain a copy of your stored records.</li>
                <li><b>Right to Rectification (Art. 16): </b>You can request correction of inaccurate or incomplete personal information.</li>
                <li><b>Right to Erasure / "Right to be Forgotten" (Art. 17): </b>You may request the immediate deletion of your personal data before our standard 1-year automated purging schedule, provided no overriding statutory retention obligation exists.</li>
                <li><b>Right to Restriction of Processing (Art. 18): </b>You can ask us to suspend processing of your data under certain circumstances.</li>
                <li><b>Right to Data Portability (Art. 20): </b>You have the right to receive your personal data in a structured, commonly used, and machine-readable format.</li>
                <li><b>Right to Object (Art. 21): </b>You can object to processing based on legitimate interests.</li>
            </ul>
            <p>To exercise any of these rights, please email us at <a href="mailto:$contactEmail">$contactEmail</a>. We verify all requests to protect your privacy and will respond within <strong>30 calendar days</strong> without charge.</p>

            <h3>9. Right to Lodge a Complaint with a Supervisory Authority</h3>
            <p>If you believe that our collection or processing of your personal information infringes upon the General Data Protection Regulation, you have the right to lodge a complaint with a supervisory authority, in particular in the EU Member State of your habitual residence, place of work, or place of the alleged infringement.</p>

            <h3>10. Contact the Data Controller</h3>
            <p>If you have questions, concerns, or requests regarding this Privacy Policy or how your personal information is handled, please contact:</p>
            <p>
            <strong>$orgName</strong><br>
            Data Protection & Web Projects Administration<br>
            Email: <a href="mailto:$contactEmail">$contactEmail</a>
            </p>
    HTML;

        return [
            '#type' => 'markup',
            '#markup' => $content,
        ];
    }
}