<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Page;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\omnia\Config\OmniaSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

class AboutPage extends ControllerBase
{
    protected MembershipSettings $membershipSettings;
    protected OmniaSettings $omniaSettings;
    protected Extension $module;

    public function __construct(
        ConfigFactoryInterface $configFactory,
        ModuleHandlerInterface $moduleHandler,
    )
    {
        $this->membershipSettings = new MembershipSettings($configFactory);
        $this->omniaSettings = new OmniaSettings($configFactory);

        $this->module = $moduleHandler->getModule('esn_membership_manager');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var ModuleHandlerInterface $moduleHandler */
        $moduleHandler = $container->get('module_handler');

        return new static(
            $configFactory,
            $moduleHandler
        );
    }

    public function aboutPage(): array
    {
        $passName = htmlspecialchars($this->membershipSettings->getPassName());
        $guestPassName = htmlspecialchars($this->membershipSettings->getGuestPassName());
        $orgName = htmlspecialchars($this->omniaSettings->getOrganisationName());

        $applyUrl = Url::fromRoute('esn_membership_manager.apply')->toString();
        $dashboardUrl = Url::fromRoute('esn_membership_manager.dashboard')->toString();
        $tosUrl = Url::fromRoute('esn_membership_manager.terms_of_service')->toString();
        $privacyUrl = Url::fromRoute('esn_membership_manager.privacy_policy')->toString();

        $logoSVG = '';
        $path = $this->module->getPath() . '/assets/images/logo.svg';
        if (file_exists($path)) {
            $logoSVG = file_get_contents($path);
        }

        $html = <<<HTML
            <div class="esn-membership-manager-dashboard" style="margin-top: 1rem; margin-bottom: 3rem;">
                <div class="header">
                    <div>
                        <h2>About Your Mobility Membership</h2>
                        <p class="text-muted">Welcome to $orgName! Here is everything you need to know about your pass, the ESNcard, and your benefits.</p>
                    </div>
                </div>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <span>DIGITAL IDENTIFIER</span>
                        </div>
                        <div class="stat-value">$passName</div>
                        <div class="stat-subtext" style="color: #2e7d32; font-weight: 700; margin-bottom: 0.75rem;">100% Free</div>
                        <p class="text-small text-muted" style="margin: 0 0 1rem 0;">
                            Your verified digital student pass in Apple or Google Wallet. Grants access to local ESN events, trips, parties, and Guest Pass privileges.
                        </p>
                        <div style="margin-top: auto;">
                            <span class="badge badge-success">
                                <span class="material-symbols-outlined">check_circle</span> Event Entry
                            </span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <span>FULL MEMBERSHIP</span>
                        </div>
                        <div class="stat-value">ESNcard</div>
                        <div class="stat-subtext" style="color: var(--brand-primary); font-weight: 700; margin-bottom: 0.75rem;">Physical Card + Digital Pass</div>
                        <p class="text-small text-muted" style="margin: 0 0 1rem 0;">
                            The official membership card of the Erasmus Generation across 40+ countries. Unlocks exclusive member privileges and community perks.
                        </p>
                        <div style="margin-top: auto;">
                            <span class="badge badge-success">
                                <span class="material-symbols-outlined">stars</span> Europe-wide Perks
                            </span>
                        </div>
                    </div>
                    <div class="stat-card stat-action-card">
                        <div class="stat-header">
                            <span>READY TO JOIN?</span>
                        </div>
                        <div class="stat-value" style="font-size: 1.25rem;">Start Your Application</div>
                        <p class="text-small text-muted" style="margin: 0 0 1rem 0;">
                            Complete your application in under 3 minutes and become a member of the Erasmus Generation.
                        </p>
                        <span style="height: stretch;"></span>
                        <a href="$applyUrl" class="secondary-button" style="text-align: center;">Apply Now</a>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-header">
                        <h3>Membership Tier Comparison</h3>
                        <span class="badge badge-success">
                            <span class="material-symbols-outlined">verified</span> Valid for 1 Year
                        </span>
                    </div>
                    <div class="panel-body" style="padding: 0;">
                        <div class="document-list">
                            <div class="document-item">
                                <div class="document-info">
                                    <div class="icon-wrap" style="aspect-ratio: 1;"><span class="material-symbols-outlined">celebration</span></div>
                                    <div>
                                        <div class="document-title">ESN Events, Cultural Activities & Socials</div>
                                        <div class="text-muted text-sm">Full access to local community gatherings, welcome week & excursions.</div>
                                    </div>
                                </div>
                                <div>
                                    <span class="badge badge-success">Both Passes</span>
                                </div>
                            </div>
                            <div class="document-item">
                                <div class="document-info">
                                    <div class="icon-wrap" style="aspect-ratio: 1;"><span class="material-symbols-outlined">confirmation_number</span></div>
                                    <div>
                                        <div class="document-title">Trip & Event Pricing</div>
                                        <div class="text-muted text-sm">Both pass types receive member rates, but ESNcard holders enjoy our highest tier of discounts on trips!</div>
                                    </div>
                                </div>
                                <div>
                                    <span class="badge badge-warning">Better with ESNcard</span>
                                </div>
                            </div>
                            <div class="document-item">
                                <div class="document-info">
                                    <div class="icon-wrap" style="aspect-ratio: 1;"><span class="material-symbols-outlined">group_add</span></div>
                                    <div>
                                        <div class="document-title">$guestPassName Privileges</div>
                                        <div class="text-muted text-sm">Invite visiting friends and family to selected events.</div>
                                    </div>
                                </div>
                                <div>
                                    <span class="badge badge-success">Both Passes</span>
                                </div>
                            </div>
                            <div class="document-item">
                                <div class="document-info">
                                    <div class="icon-wrap" style="aspect-ratio: 1;"><span class="material-symbols-outlined">travel_explore</span></div>
                                    <div>
                                        <div class="document-title">International & Local Partner Deals</div>
                                        <div class="text-muted text-sm">Discounts across travel, accommodation, transport, food & drink, sports, and local services across Europe.</div>
                                    </div>
                                </div>
                                <div>
                                    <span class="badge badge-warning">ESNcard Only</span>
                                </div>
                            </div>
                            <div class="document-item">
                                <div class="document-info">
                                    <div class="icon-wrap" style="aspect-ratio: 1;"><span class="material-symbols-outlined">campaign</span></div>
                                    <div>
                                        <div class="document-title">Upcoming Member Perks & Privileges</div>
                                        <div class="text-muted text-sm">Stay tuned! We have exciting new perks in the works for our members.</div>
                                    </div>
                                </div>
                                <div>
                                    <span class="badge badge-warning">Coming Soon</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-header">
                        <h3>How to Apply: The Process</h3>
                    </div>
                    <div class="panel-body" style="padding: 0;">
                        <div class="document-list">
                            <div class="document-item">
                                <div class="document-info">
                                    <div class="icon-wrap" style="background-color: var(--brand-secondary-light); color: var(--brand-secondary-dark); font-weight: bold; aspect-ratio: 1;">1</div>
                                    <div>
                                        <div class="document-title">Passwordless Authentication</div>
                                        <div class="text-muted text-sm">Enter your email to receive an instant 6-digit one-time code.</div>
                                    </div>
                                </div>
                                <span class="badge badge-success"><span class="material-symbols-outlined">lock_open</span> Instant</span>
                            </div>
                            <div class="document-item">
                                <div class="document-info">
                                    <div class="icon-wrap" style="background-color: var(--brand-secondary-light); color: var(--brand-secondary-dark); font-weight: bold; aspect-ratio: 1;">2</div>
                                    <div>
                                        <div class="document-title">Verify Identity</div>
                                        <div class="text-muted text-sm">Scan your ID with your camera for instant verification, or upload a copy for manual processing.</div>
                                    </div>
                                </div>
                                <span class="badge badge-success"><span class="material-symbols-outlined">id_card</span> Automated</span>
                            </div>
                            <div class="document-item">
                                <div class="document-info">
                                    <div class="icon-wrap" style="background-color: var(--brand-secondary-light); color: var(--brand-secondary-dark); font-weight: bold; aspect-ratio: 1;">3</div>
                                    <div>
                                        <div class="document-title">Mobility Status & Host Institution</div>
                                        <div class="text-muted text-sm">Select your exchange programme and attach proof (Learning Agreement, Acceptance Letter, or ESN Accounts login).</div>
                                    </div>
                                </div>
                                <span class="badge badge-success"><span class="material-symbols-outlined">school</span> Verified</span>
                            </div>
                            <div class="document-item">
                                <div class="document-info">
                                    <div class="icon-wrap" style="background-color: var(--brand-secondary-light); color: var(--brand-secondary-dark); font-weight: bold; aspect-ratio: 1;">4</div>
                                    <div>
                                        <div class="document-title">Choose Card & Upload Photo</div>
                                        <div class="text-muted text-sm">Keep the free pass, or tick ESNcard and upload a 4:5 passport-style photo for card printing.</div>
                                    </div>
                                </div>
                                <span class="badge badge-success"><span class="material-symbols-outlined">add_a_photo</span> 4:5 Ratio</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-header">
                        <h3>Digital Wallet Compatibility</h3>
                        <span class="text-muted text-sm">Instant Barcode Check-in</span>
                    </div>
                    <div class="divided-panel-body">
                        <div>
                            <h4>$passName & ESNcard</h4>
                            <p class="text-small text-muted" style="text-align: center; margin: 0 0 10px 0;">Add your pass to your phone with one tap once your application is approved.</p>
                            <div style="display: flex; gap: 15px; flex-wrap: wrap; justify-content: center; align-items: center;">
                                <img alt="Add to Google Wallet" src="https://esncy.org/sites/default/files/2025-12/add_to_google_wallet_badge.png" height="42">
                                <img alt="Add to Apple Wallet" src="https://esncy.org/sites/default/files/2026-02/Add_to_Apple_Wallet.png" height="42">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="alert" style="background-color: #f7f9fc; border: 1px solid var(--border-light); color: var(--text-dark); justify-content: flex-start; max-width: 800px; margin: 0 auto 2rem;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 0.5rem;">
                            <span class="material-symbols-outlined" style="color: var(--brand-secondary);">info</span>
                            <h3 style="margin: 0; font-size: 1.1rem; color: var(--brand-primary);">Have Friends Visiting You?</h3>
                        </div>
                        <p style="margin: 0; font-size: 0.95rem; line-height: 1.5;">
                            Through your <strong>Membership Dashboard</strong>, you can request temporary <strong>{$guestPassName}es</strong> for visiting friends or family. Guest passes are valid for 7 days upon approval. Guests must arrive at the venue accompanied by you and must present a matching government photo ID.
                        </p>
                    </div>
                </div>
                <div style="text-align: center; margin: 3rem 0 1.5rem;">
                    <a href="$applyUrl" class="main-button" style="background-color: var(--brand-primary); color: #fff; padding: 1rem 2.5rem; font-size: 1.1rem; border-radius: var(--radius-sm); text-decoration: none; display: inline-block;">
                        Apply for Your Membership
                    </a>
                    <div style="margin-top: 1rem;">
                        <a href="$dashboardUrl" class="panel-button-text" style="display: inline-flex; justify-content: center;">
                            Already applied? Go to your Membership Dashboard &rarr;
                        </a>
                    </div>
                    <p class="text-small text-muted" style="margin-top: 1.5rem;">
                        Your data is strictly protected. Documents are deleted within 14 days under GDPR regulations.
                        Read our <a href="$tosUrl" target="_blank" style="color: var(--brand-secondary);">Terms of Service</a> and <a href="$privacyUrl" target="_blank" style="color: var(--brand-secondary);">Privacy Policy</a>.
                    </p>
                </div>
                <div class="esn-membership-manager-form" style="border-top: 1px solid var(--border-light); padding-top: 1.5rem; margin-top: 2rem;">
                    <span class="powered-by-text" >Powered by <a href="https://github.com/esn-cy/ESN-Membership-Manager" target="_blank">ESN Membership Manager $logoSVG</a>.<br>Made in Cyprus with ❤️.</span>
                </div>
            </div>
        HTML;

        return [
            '#type' => 'markup',
            '#markup' => Markup::create($html),
            '#attached' => [
                'library' => [
                    'esn_membership_manager/membership_dashboard',
                    'esn_membership_manager/application_form',
                ],
            ],
        ];
    }
}