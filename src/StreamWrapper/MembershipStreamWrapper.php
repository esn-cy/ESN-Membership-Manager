<?php

namespace Drupal\esn_membership_manager\StreamWrapper;

use Drupal\Core\GeneratedUrl;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Defines a custom stream wrapper for ESN Membership Manager (membership://).
 */
class MembershipStreamWrapper extends PrivateStream
{
    static protected bool $secureChecked = FALSE;

    /**
     * {@inheritdoc}
     */
    public function getName(): string|TranslatableMarkup
    {
        return t('ESN Membership Manager Files');
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string|TranslatableMarkup
    {
        return t('Dedicated private storage for ESN Membership Manager applications.');
    }

    /**
     * {@inheritdoc}
     */
    public function getExternalUrl(): GeneratedUrl|string
    {
        $path = str_replace('\\', '/', $this->getTarget());
        return Url::fromRoute('system.files', ['scheme' => 'membership'], [
            'query' => ['file' => $path],
            'absolute' => TRUE,
        ])->toString();
    }

    /**
     * {@inheritdoc}
     */
    public function mkdir($uri, $mode, $options): bool
    {
        $result = parent::mkdir($uri, $mode, $options);
        if ($result) {
            $this->ensureHtaccess();
        }
        return $result;
    }

    /**
     * Ensures that the private directory is protected by an .htaccess file.
     */
    protected function ensureHtaccess(): void
    {
        $directory = $this->getDirectoryPath();

        if (is_dir($directory)) {
            $htaccess_path = $directory . '/.htaccess';
            if (!file_exists($htaccess_path)) {
                $content = "Order deny,allow\nDeny from all\n";
                $content .= "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n";
                file_put_contents($htaccess_path, $content);
            }
            self::$secureChecked = TRUE;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getDirectoryPath(): string
    {
        return DRUPAL_ROOT . '/../../private/esn_membership_manager_storage';
    }

    /**
     * {@inheritdoc}
     */
    public function stream_open($uri, $mode, $options, &$opened_path): bool
    {
        if (!self::$secureChecked)
            $this->ensureHtaccess();
        return parent::stream_open($uri, $mode, $options, $opened_path);
    }
}