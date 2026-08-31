<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\StreamWrapper;

use Drupal\Core\GeneratedUrl;
use Drupal\Core\Url;
use Drupal\omnia\StreamWrapper\StreamWrapperBase;

/**
 * Defines a custom stream wrapper for ESN Membership Manager (membership://).
 */
class MembershipStreamWrapper extends StreamWrapperBase
{

    function moduleMachineName(): string
    {
        return 'esn_membership_manager';
    }

    function moduleFormatedName(): string
    {
        return 'ESN Membership Manager';
    }

    function isPrivate(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getExternalUrl(): GeneratedUrl|string
    {
        $path = str_replace('\\', '/', $this->getTarget());
        $parts = explode('/', $path, 2);

        if (count($parts) < 2) {
            return Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();
        }

        return Url::fromRoute('esn_membership_manager.file_download', [
            'applicationID' => $parts[0],
            'filename' => $parts[1]
        ], ['absolute' => TRUE])->toString();
    }
}