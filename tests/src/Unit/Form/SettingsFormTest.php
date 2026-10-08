<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\State\StateInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Form\SettingsForm;
use Drupal\esn_membership_manager\Service\WeeztixService;
use Drupal\omnia\Config\OmniaSettings;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for SettingsForm.
 *
 * @covers       \Drupal\esn_membership_manager\Form\SettingsForm
 * @uses         \Drupal\esn_membership_manager\Config\MembershipSettings
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class SettingsFormTest extends MembershipManagerTestCase
{
    private ConfigFactoryInterface $configFactory;
    private WeeztixService $weeztixService;
    private StateInterface $state;
    private FileSystemInterface $fileSystem;
    private ModuleHandlerInterface $moduleHandler;
    private MessengerInterface $messenger;
    private RequestStack $requestStack;
    private SettingsForm $form;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
            OmniaSettings::CONFIG_NAME => [],
        ]);
        $typedConfigManager = $this->createMock(TypedConfigManagerInterface::class);
        $this->weeztixService = $this->createMock(WeeztixService::class);
        $this->state = $this->createMock(StateInterface::class);
        $this->fileSystem = $this->createMock(FileSystemInterface::class);
        $this->fileSystem->method('getTempDirectory')->willReturn(sys_get_temp_dir());
        $this->fileSystem->method('tempnam')->willReturnCallback(fn($dir, $prefix) => tempnam($dir, $prefix));
        $this->moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $this->messenger = $this->createMock(MessengerInterface::class);

        $this->requestStack = new RequestStack();
        $this->requestStack->push(new Request());

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generateFromRoute')->willReturn('https://example.com/admin/config/omnia');

        $this->container->set('config.factory', $this->configFactory);
        $this->container->set('esn_membership_manager.weeztix_service', $this->weeztixService);
        $this->container->set('state', $this->state);
        $this->container->set('file_system', $this->fileSystem);
        $this->container->set('module_handler', $this->moduleHandler);
        $this->container->set('messenger', $this->messenger);
        $this->container->set('request_stack', $this->requestStack);
        $this->container->set('url_generator', $urlGenerator);

        $this->form = new SettingsForm(
            $this->configFactory,
            $typedConfigManager,
            $this->weeztixService,
            $this->state,
            $this->fileSystem,
            $this->moduleHandler
        );
        $this->form->setMessenger($this->messenger);
        $this->form->setStringTranslation($this->container->get('string_translation'));
        $this->form->setRequestStack($this->requestStack);
    }

    public function testGetFormId(): void
    {
        $this->assertEquals('esn_membership_manager_settings_form', $this->form->getFormId());
    }

    public function testCreate(): void
    {
        $form = SettingsForm::create($this->container);
        $this->assertInstanceOf(SettingsForm::class, $form);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetEditableConfigNames(): void
    {
        $ref = new ReflectionMethod(SettingsForm::class, 'getEditableConfigNames');
        $this->assertEquals([MembershipSettings::CONFIG_NAME], $ref->invoke($this->form));
    }

    public function testValidateFormWithoutFiles(): void
    {
        $form = [];
        $formState = new FormState();

        $this->messenger->expects($this->never())->method('addError');
        $this->form->validateForm($form, $formState);

        $this->assertNull($formState->get('apple_certificate_string_p12'));
        $this->assertNull($formState->get('apple_certificate_string_pem'));
    }

    public function testValidateFormWithInvalidUploadedFile(): void
    {
        $form = [];
        $formState = new FormState();

        $tempFile = tempnam(sys_get_temp_dir(), 'cert_inv_');
        file_put_contents($tempFile, 'dummy');

        $uploadedFile = new UploadedFile($tempFile, 'cert.p12', null, UPLOAD_ERR_CANT_WRITE, true);
        $this->requestStack->getCurrentRequest()->files->set('files', [
            'apple_certificate_file' => $uploadedFile,
        ]);

        $this->messenger->expects($this->never())->method('addError');
        $this->form->validateForm($form, $formState);

        $this->assertNull($formState->get('apple_certificate_string_p12'));
        $this->assertNull($formState->get('apple_certificate_string_pem'));

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    public function testValidateFormWithValidP12AndCorrectPassword(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('apple_certificate_password', 'secret123');

        $dn = ['commonName' => 'Test Cert'];
        $privkey = openssl_pkey_new();
        $cert = openssl_csr_sign(openssl_csr_new($dn, $privkey), null, $privkey, 365);
        $p12Content = '';
        openssl_pkcs12_export($cert, $p12Content, $privkey, 'secret123');

        $tempFile = tempnam(sys_get_temp_dir(), 'cert_valid_');
        file_put_contents($tempFile, $p12Content);

        $uploadedFile = new UploadedFile($tempFile, 'cert.p12', 'application/x-pkcs12', UPLOAD_ERR_OK, true);
        $this->requestStack->getCurrentRequest()->files->set('files', [
            'apple_certificate_file' => $uploadedFile,
        ]);

        $this->messenger->expects($this->never())->method('addError');
        $this->form->validateForm($form, $formState);

        $this->assertEquals($p12Content, $formState->get('apple_certificate_string_p12'));
        $this->assertNotNull($formState->get('apple_certificate_string_pem'));
        $this->assertStringContainsString('CERTIFICATE', $formState->get('apple_certificate_string_pem'));

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    public function testValidateFormWithValidP12AndWrongPassword(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('apple_certificate_password', 'wrong_password');

        $dn = ['commonName' => 'Test Cert'];
        $privkey = openssl_pkey_new();
        $cert = openssl_csr_sign(openssl_csr_new($dn, $privkey), null, $privkey, 365);
        $p12Content = '';
        openssl_pkcs12_export($cert, $p12Content, $privkey, 'secret123');

        $tempFile = tempnam(sys_get_temp_dir(), 'cert_wrong_');
        file_put_contents($tempFile, $p12Content);

        $uploadedFile = new UploadedFile($tempFile, 'cert.p12', 'application/x-pkcs12', UPLOAD_ERR_OK, true);
        $this->requestStack->getCurrentRequest()->files->set('files', [
            'apple_certificate_file' => $uploadedFile,
        ]);

        $this->messenger->expects($this->once())
            ->method('addError');

        $this->form->validateForm($form, $formState);

        $this->assertEquals($p12Content, $formState->get('apple_certificate_string_p12'));
        $this->assertNull($formState->get('apple_certificate_string_pem'));

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    private function createLegacyP12(): string
    {
        $keyFile = tempnam(sys_get_temp_dir(), 'k_');
        $certFile = tempnam(sys_get_temp_dir(), 'c_');
        $p12File = tempnam(sys_get_temp_dir(), 'p_');

        shell_exec("openssl req -x509 -newkey rsa:1024 -keyout " . escapeshellarg($keyFile) . " -out " . escapeshellarg($certFile) . " -days 1 -nodes -subj '/CN=Legacy' 2>/dev/null");
        shell_exec("openssl pkcs12 -export -out " . escapeshellarg($p12File) . " -inkey " . escapeshellarg($keyFile) . " -in " . escapeshellarg($certFile) . " -passout " . escapeshellarg("pass:" . 'legacypass') . " -legacy 2>/dev/null");

        $content = (string)file_get_contents($p12File);
        @unlink($keyFile);
        @unlink($certFile);
        @unlink($p12File);

        return $content;
    }

    public function testValidateFormWithLegacyP12Success(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('apple_certificate_password', 'legacypass');

        $p12Content = $this->createLegacyP12();

        $tempFile = tempnam(sys_get_temp_dir(), 'cert_leg_');
        file_put_contents($tempFile, $p12Content);

        $uploadedFile = new UploadedFile($tempFile, 'cert.p12', 'application/x-pkcs12', UPLOAD_ERR_OK, true);
        $this->requestStack->getCurrentRequest()->files->set('files', [
            'apple_certificate_file' => $uploadedFile,
        ]);

        $this->messenger->expects($this->never())->method('addError');
        $this->form->validateForm($form, $formState);

        $this->assertEquals($p12Content, $formState->get('apple_certificate_string_p12'));
        $this->assertNotNull($formState->get('apple_certificate_string_pem'));
        $this->assertStringContainsString('CERTIFICATE', $formState->get('apple_certificate_string_pem'));

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    /**
     * @throws Exception
     */
    public function testValidateFormWithLegacyP12TempnamFailure(): void
    {
        $fileSystem = $this->createMock(FileSystemInterface::class);
        $fileSystem->method('getTempDirectory')->willReturn(sys_get_temp_dir());
        $fileSystem->method('tempnam')->willReturn('');

        $formInstance = new SettingsForm(
            $this->configFactory,
            $this->container->get('config.typed'),
            $this->weeztixService,
            $this->state,
            $fileSystem,
            $this->moduleHandler
        );
        $formInstance->setMessenger($this->messenger);
        $formInstance->setStringTranslation($this->container->get('string_translation'));
        $formInstance->setRequestStack($this->requestStack);

        $form = [];
        $formState = new FormState();
        $formState->setValue('apple_certificate_password', 'legacypass');

        $p12Content = $this->createLegacyP12();
        $tempFile = tempnam(sys_get_temp_dir(), 'cert_leg_fail_');
        file_put_contents($tempFile, $p12Content);

        $uploadedFile = new UploadedFile($tempFile, 'cert.p12', 'application/x-pkcs12', UPLOAD_ERR_OK, true);
        $this->requestStack->getCurrentRequest()->files->set('files', [
            'apple_certificate_file' => $uploadedFile,
        ]);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Could not create temporary certificate file.'));

        $formInstance->validateForm($form, $formState);

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    /**
     * @throws Exception
     */
    public function testValidateFormWithLegacyP12WriteFailure(): void
    {
        $fileSystem = $this->createMock(FileSystemInterface::class);
        $fileSystem->method('getTempDirectory')->willReturn(sys_get_temp_dir());
        $fileSystem->method('tempnam')->willReturn('/nonexistent_directory_unwriteable/file');

        $formInstance = new SettingsForm(
            $this->configFactory,
            $this->container->get('config.typed'),
            $this->weeztixService,
            $this->state,
            $fileSystem,
            $this->moduleHandler
        );
        $formInstance->setMessenger($this->messenger);
        $formInstance->setStringTranslation($this->container->get('string_translation'));
        $formInstance->setRequestStack($this->requestStack);

        $form = [];
        $formState = new FormState();
        $formState->setValue('apple_certificate_password', 'legacypass');

        $p12Content = $this->createLegacyP12();
        $tempFile = tempnam(sys_get_temp_dir(), 'cert_leg_write_');
        file_put_contents($tempFile, $p12Content);

        $uploadedFile = new UploadedFile($tempFile, 'cert.p12', 'application/x-pkcs12', UPLOAD_ERR_OK, true);
        $this->requestStack->getCurrentRequest()->files->set('files', [
            'apple_certificate_file' => $uploadedFile,
        ]);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Could not write to the temporary certificate file.'));

        $formInstance->validateForm($form, $formState);

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    /**
     * @throws Exception
     */
    public function testValidateFormWithLegacyP12ExecutionFailure(): void
    {
        $formInstance = new class(
            $this->configFactory,
            $this->container->get('config.typed'),
            $this->weeztixService,
            $this->state,
            $this->fileSystem,
            $this->moduleHandler
        ) extends SettingsForm {
            protected function executeOpenSSL(string $certificatePath, string $password): ?string
            {
                return '';
            }
        };
        $formInstance->setMessenger($this->messenger);
        $formInstance->setStringTranslation($this->container->get('string_translation'));
        $formInstance->setRequestStack($this->requestStack);

        $form = [];
        $formState = new FormState();
        $formState->setValue('apple_certificate_password', 'legacypass');

        $p12Content = $this->createLegacyP12();
        $tempFile = tempnam(sys_get_temp_dir(), 'cert_leg_exec_');
        file_put_contents($tempFile, $p12Content);

        $uploadedFile = new UploadedFile($tempFile, 'cert.p12', 'application/x-pkcs12', UPLOAD_ERR_OK, true);
        $this->requestStack->getCurrentRequest()->files->set('files', [
            'apple_certificate_file' => $uploadedFile,
        ]);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Could not read certificate file.'));

        $formInstance->validateForm($form, $formState);

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    public function testSubmitFormFullConfiguration(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'switch_guest_pass' => true,
            'switch_estia' => true,
            'switch_weeztix' => true,
            'switch_google_sheets' => true,
            'switch_google_wallet' => true,
            'switch_apple_wallet' => true,
            'switch_didit' => true,
            'pass_name' => 'ESN Pass',
            'email_address' => 'info@esn.org',
            'email_name' => 'ESN Admin',
            'email_footer' => '<p>Footer</p>',
            'email_admin_address' => 'admin@esn.org',
            'guest_pass_name' => 'Guest Pass',
            'guest_pass_instant_limit' => 2,
            'guest_pass_special_per_person_limit' => 5,
            'guest_pass_special_concurrent_limit' => 3,
            'stripe_webhook_secret' => 'whsec_test',
            'stripe_price_esncard' => 'price_card',
            'stripe_price_processing' => 'price_proc',
            'stripe_price_esncard_esner' => 'price_card_esner',
            'stripe_price_processing_esner' => 'price_proc_esner',
            'weeztix_client_id' => 'w_client',
            'weeztix_client_secret' => 'w_secret',
            'weeztix_pass_coupon_list_id' => 'w_pass_list',
            'weeztix_card_coupon_list_id' => 'w_card_list',
            'google_spreadsheet_id' => 'sheet_123',
            'google_sheet_name' => 'Sheet1',
            'apple_certificate_password' => 'apple_pw',
            'apple_pass_type_id' => 'pass.esn.membership',
            'didit_api_key' => 'didit_key',
            'didit_workflow_id' => 'didit_wf',
            'guest_pass_special_mobilities' => ['erasmus', 'trainee'],
            'guest_pass_special_interval_number' => 3,
            'guest_pass_special_interval_period' => 'M',
        ]);
        $formState->set('apple_certificate_string_p12', 'dummy_p12_content');
        $formState->set('apple_certificate_string_pem', 'dummy_pem_content');

        $this->moduleHandler->method('moduleExists')->with('estia_housing')->willReturn(true);

        $this->messenger->expects($this->exactly(2))
            ->method('addStatus');

        $this->form->submitForm($form, $formState);
    }

    public function testSubmitFormAlternativeBranches(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'switch_guest_pass' => false,
            'switch_estia' => true,
            'switch_weeztix' => false,
            'switch_google_sheets' => false,
            'switch_google_wallet' => false,
            'switch_apple_wallet' => false,
            'switch_didit' => false,
            'pass_name' => 'Pass',
            'email_address' => 'a@b.com',
            'email_name' => 'Admin',
            'email_footer' => '',
            'email_admin_address' => 'adm@b.com',
            'guest_pass_name' => '',
            'guest_pass_instant_limit' => 0,
            'guest_pass_special_per_person_limit' => 0,
            'guest_pass_special_concurrent_limit' => 0,
            'stripe_webhook_secret' => '',
            'stripe_price_esncard' => '',
            'stripe_price_processing' => '',
            'stripe_price_esncard_esner' => '',
            'stripe_price_processing_esner' => '',
            'weeztix_client_id' => '',
            'weeztix_client_secret' => '',
            'weeztix_pass_coupon_list_id' => '',
            'weeztix_card_coupon_list_id' => '',
            'google_spreadsheet_id' => '',
            'google_sheet_name' => '',
            'apple_certificate_password' => '',
            'apple_pass_type_id' => '',
            'didit_api_key' => '',
            'didit_workflow_id' => '',
            'guest_pass_special_mobilities' => 'single_mobility',
            'guest_pass_special_interval_number' => '',
            'guest_pass_special_interval_period' => '',
        ]);

        $this->moduleHandler->method('moduleExists')->with('estia_housing')->willReturn(false);

        $this->messenger->expects($this->once())
            ->method('addStatus')
            ->with($this->matchesString('The configuration options have been saved.'));

        $this->form->submitForm($form, $formState);
    }

    public function testSubmitFormWithEmptySpecialMobilities(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'switch_guest_pass' => false,
            'switch_estia' => false,
            'switch_weeztix' => false,
            'switch_google_sheets' => false,
            'switch_google_wallet' => false,
            'switch_apple_wallet' => false,
            'switch_didit' => false,
            'pass_name' => 'Pass',
            'email_address' => 'a@b.com',
            'email_name' => 'Admin',
            'email_footer' => '',
            'email_admin_address' => 'adm@b.com',
            'guest_pass_name' => '',
            'guest_pass_instant_limit' => 0,
            'guest_pass_special_per_person_limit' => 0,
            'guest_pass_special_concurrent_limit' => 0,
            'stripe_webhook_secret' => '',
            'stripe_price_esncard' => '',
            'stripe_price_processing' => '',
            'stripe_price_esncard_esner' => '',
            'stripe_price_processing_esner' => '',
            'weeztix_client_id' => '',
            'weeztix_client_secret' => '',
            'weeztix_pass_coupon_list_id' => '',
            'weeztix_card_coupon_list_id' => '',
            'google_spreadsheet_id' => '',
            'google_sheet_name' => '',
            'apple_certificate_password' => '',
            'apple_pass_type_id' => '',
            'didit_api_key' => '',
            'didit_workflow_id' => '',
            'guest_pass_special_mobilities' => [],
            'guest_pass_special_interval_number' => 5,
            'guest_pass_special_interval_period' => '',
        ]);

        $this->form->submitForm($form, $formState);
        $this->assertFalse($formState->hasAnyErrors());
    }

    public function testSwitchToggleNoSwitches(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'switch_google_sheets' => false,
            'switch_google_wallet' => false,
            'switch_apple_wallet' => false,
        ]);

        $this->messenger->expects($this->never())->method('addError');
        $result = $this->form->switchToggle($form, $formState);
        $this->assertEquals($form, $result);
    }

    public function testSwitchToggleGoogleDisabledInOmnia(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'switch_google_sheets' => true,
            'switch_google_wallet' => false,
            'switch_apple_wallet' => false,
        ]);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('<p>Please enable the Google integration in the <a href="https://example.com/admin/config/omnia">Omnia Settings</a> before you enable the integration here.</p>'));

        $this->form->switchToggle($form, $formState);
        $this->assertFalse($formState->getValue('switch_google_sheets'));
        $this->assertFalse($formState->getValue('switch_google_wallet'));
    }

    public function testSwitchToggleGoogleMissingCredentialsInOmnia(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
            OmniaSettings::CONFIG_NAME => [
                'switch_google' => true,
                'google_client_email' => '',
            ],
        ]);
        $this->container->set('config.factory', $configFactory);
        $this->form->setConfigFactory($configFactory);

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'switch_google_sheets' => true,
            'switch_google_wallet' => true,
            'switch_apple_wallet' => false,
        ]);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('<p>Please configure the Google Credentials in the <a href="https://example.com/admin/config/omnia">Omnia Settings</a> before you enable the integration here.</p>'));

        $this->form->switchToggle($form, $formState);
        $this->assertFalse($formState->getValue('switch_google_sheets'));
        $this->assertFalse($formState->getValue('switch_google_wallet'));
    }

    public function testSwitchToggleGoogleWalletMissingIssuerIdInOmnia(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
            OmniaSettings::CONFIG_NAME => [
                'switch_google' => true,
                'google_client_email' => 'client@google.com',
                'google_issuer_id' => '',
            ],
        ]);
        $this->container->set('config.factory', $configFactory);
        $this->form->setConfigFactory($configFactory);

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'switch_google_sheets' => true,
            'switch_google_wallet' => true,
            'switch_apple_wallet' => false,
        ]);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('<p>Please configure the Google Wallet Issuer ID in the <a href="https://example.com/admin/config/omnia">Omnia Settings</a> before you enable the integration here.</p>'));

        $this->form->switchToggle($form, $formState);
        $this->assertTrue($formState->getValue('switch_google_sheets'));
        $this->assertFalse($formState->getValue('switch_google_wallet'));
    }

    public function testSwitchToggleGoogleSuccess(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
            OmniaSettings::CONFIG_NAME => [
                'switch_google' => true,
                'google_client_email' => 'client@google.com',
                'google_issuer_id' => 'issuer_123',
            ],
        ]);
        $this->container->set('config.factory', $configFactory);
        $this->form->setConfigFactory($configFactory);

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'switch_google_sheets' => true,
            'switch_google_wallet' => true,
            'switch_apple_wallet' => false,
        ]);

        $this->messenger->expects($this->never())->method('addError');

        $this->form->switchToggle($form, $formState);
        $this->assertTrue($formState->getValue('switch_google_sheets'));
        $this->assertTrue($formState->getValue('switch_google_wallet'));
    }

    public function testSwitchToggleAppleDisabledInOmnia(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'switch_google_sheets' => false,
            'switch_google_wallet' => false,
            'switch_apple_wallet' => true,
        ]);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('<p>Please enable the Apple integration in the <a href="https://example.com/admin/config/omnia">Omnia Settings</a> before you enable the integration here.</p>'));

        $this->form->switchToggle($form, $formState);
        $this->assertFalse($formState->getValue('switch_apple_wallet'));
    }

    public function testSwitchToggleAppleMissingTeamIdInOmnia(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
            OmniaSettings::CONFIG_NAME => [
                'switch_apple' => true,
                'apple_team_id' => '',
            ],
        ]);
        $this->container->set('config.factory', $configFactory);
        $this->form->setConfigFactory($configFactory);

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'switch_google_sheets' => false,
            'switch_google_wallet' => false,
            'switch_apple_wallet' => true,
        ]);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('<p>Please configure the Apple Team ID in the <a href="https://example.com/admin/config/omnia">Omnia Settings</a> before you enable the integration here.</p>'));

        $this->form->switchToggle($form, $formState);
        $this->assertFalse($formState->getValue('switch_apple_wallet'));
    }

    public function testSwitchToggleAppleSuccess(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
            OmniaSettings::CONFIG_NAME => [
                'switch_apple' => true,
                'apple_team_id' => 'team_123',
            ],
        ]);
        $this->container->set('config.factory', $configFactory);
        $this->form->setConfigFactory($configFactory);

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'switch_google_sheets' => false,
            'switch_google_wallet' => false,
            'switch_apple_wallet' => true,
        ]);

        $this->messenger->expects($this->never())->method('addError');

        $this->form->switchToggle($form, $formState);
        $this->assertTrue($formState->getValue('switch_apple_wallet'));
    }
}
