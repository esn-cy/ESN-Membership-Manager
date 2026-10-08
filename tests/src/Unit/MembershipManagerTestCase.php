<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit;

use Drupal;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Base test case for ESN Membership Manager unit tests.
 */
abstract class MembershipManagerTestCase extends TestCase
{
    // Common Test Names.
    public const TEST_FIRST_NAME = 'John';
    public const TEST_LAST_NAME = 'Doe';
    public const TEST_FULL_NAME = 'John Doe';
    public const TEST_SECONDARY_FIRST_NAME = 'Jane';
    public const TEST_SECONDARY_LAST_NAME = 'Doe';
    public const TEST_SECONDARY_FULL_NAME = 'Jane Doe';
    public const TEST_TERTIARY_FIRST_NAME = 'Alice';
    public const TEST_TERTIARY_LAST_NAME = 'Smith';
    public const TEST_TERTIARY_FULL_NAME = 'Alice Smith';

    // Common Test Emails.
    public const TEST_EMAIL = 'student@example.com';
    public const TEST_SECONDARY_EMAIL = 'alice@example.com';
    public const TEST_USER_EMAIL = 'user@example.com';
    public const TEST_ADMIN_EMAIL = 'admin@esncy.org';
    public const TEST_BLOCKED_EMAIL = 'blocked@example.com';

    // Common Test ESNcard Numbers.
    public const TEST_CARD_NUMBER = '1234567ABCD1';
    public const TEST_SECONDARY_CARD_NUMBER = '9999999ABCD2';

    // Common Test Metadata.
    public const TEST_PHONE = '+35799123456';
    public const TEST_BIRTH_DATE = '2000-01-01';
    public const TEST_NATIONALITY = 'Cypriot';
    public const TEST_COUNTRY = 'Cyprus';
    public const TEST_SECTION = 'ESN Nicosia';
    public const TEST_HOST = 'University of Cyprus';
    public const TEST_TOKEN = 'TOKEN123';

    protected ?ContainerBuilder $container = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new ContainerBuilder();

        $languageManager = $this->createMock(LanguageManagerInterface::class);
        $defaultLanguage = new Language(['id' => 'en', 'name' => 'English']);
        $languageManager->method('getCurrentLanguage')->willReturn($defaultLanguage);
        $languageManager->method('getDefaultLanguage')->willReturn($defaultLanguage);
        $this->container->set('language_manager', $languageManager);

        $translation = $this->createMock(TranslationInterface::class);
        $translation->method('translate')->willReturnCallback(
            fn($string) => is_object($string) && method_exists($string, 'getUntranslatedString') ? $string->getUntranslatedString() : (string)$string
        );
        $translation->method('translateString')->willReturnCallback(
            fn(TranslatableMarkup $markup) => $markup->getUntranslatedString()
        );
        $translation->method('formatPlural')->willReturnCallback(
            fn($count, $singular, $plural) => $count == 1 ? $singular : $plural
        );
        $this->container->set('string_translation', $translation);

        $typedConfig = $this->createMock(TypedConfigManagerInterface::class);
        $this->container->set('config.typed', $typedConfig);

        $cacheContextsManager = $this->createMock(CacheContextsManager::class);
        $cacheContextsManager->method('assertValidTokens')->willReturn(true);
        $this->container->set('cache_contexts_manager', $cacheContextsManager);

        $cacheTagsInvalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
        $this->container->set('cache_tags.invalidator', $cacheTagsInvalidator);

        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $moduleHandler->method('invokeAll')->willReturn([]);
        $this->container->set('module_handler', $moduleHandler);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $this->container->set('entity_type.manager', $entityTypeManager);

        $messenger = $this->createMock(MessengerInterface::class);
        $this->container->set('messenger', $messenger);

        $currentUser = $this->createMock(AccountProxyInterface::class);
        $this->container->set('current_user', $currentUser);

        $fileSystem = $this->createMock(FileSystemInterface::class);
        $fileSystem->method('basename')->willReturnCallback(fn($path) => basename((string)$path));
        $this->container->set('file_system', $fileSystem);

        $requestStack = new RequestStack();
        $requestStack->push(new Request());
        $this->container->set('request_stack', $requestStack);

        while (openssl_error_string()) {
        }
        (new FormState())->clearErrors();

        Drupal::setContainer($this->container);
    }

    protected function tearDown(): void
    {
        while (openssl_error_string()) {
        }
        (new FormState())->clearErrors();
        Drupal::unsetContainer();
        parent::tearDown();
    }

    /**
     * Creates a stub ConfigFactoryInterface.
     *
     * @param array<string, array<string, mixed>> $configs
     *   An associative array of config names to config values.
     *
     * @return ConfigFactoryInterface
     */
    public function getConfigFactoryStub(array $configs = []): ConfigFactoryInterface
    {
        $config_get_map = [];
        $config_editable_map = [];

        foreach ($configs as $config_name => $config_values) {
            $config_get = function ($key = '') use ($config_values) {
                if (empty($key)) {
                    return $config_values;
                }
                if (array_key_exists($key, $config_values)) {
                    return $config_values[$key];
                }
                $parts = explode('.', $key);
                $value = NestedArray::getValue($config_values, $parts, $key_exists);
                return $key_exists ? $value : null;
            };

            $immutable_config = $this->createMock(ImmutableConfig::class);
            $immutable_config->method('get')->willReturnCallback($config_get);
            $config_get_map[] = [$config_name, $immutable_config];

            $mutable_config = $this->createMock(Config::class);
            $mutable_config->method('get')->willReturnCallback($config_get);
            $mutable_config->method('set')->willReturnSelf();
            $mutable_config->method('save')->willReturnSelf();
            $config_editable_map[] = [$config_name, $mutable_config];
        }

        $config_factory = $this->createMock(ConfigFactoryInterface::class);
        $config_factory->method('get')->willReturnMap($config_get_map);
        $config_factory->method('getEditable')->willReturnMap($config_editable_map);

        return $config_factory;
    }

    /**
     * Creates a mock LoggerChannelFactory.
     */
    public function getLoggerFactoryMock(?LoggerChannelInterface $loggerChannel = null): LoggerChannelFactoryInterface
    {
        $channel = $loggerChannel ?? $this->createMock(LoggerChannelInterface::class);
        $factory = $this->createMock(LoggerChannelFactoryInterface::class);
        $factory->method('get')->willReturn($channel);
        return $factory;
    }

    /**
     * Constraint helper to assert that an object (such as TranslatableMarkup) or string matches expected string value.
     */
    public function matchesString(string $expected): Callback
    {
        return $this->callback(fn($actual) => (string)$actual === $expected);
    }
}

