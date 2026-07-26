<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\StreamWrapper\MembershipStreamWrapper;
use Drupal\omnia\Controller\FileAccessControllerBase;
use Drupal\omnia\StreamWrapper\StreamWrapperBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles file downloads for the 'membership://' scheme.
 */
class FileAccessController extends FileAccessControllerBase
{
    protected LoggerChannelInterface $logger;

    public function __construct(
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        parent::__construct($loggerFactory);
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $loggerFactory
        );
    }

    function schemeName(): string
    {
        return 'membership';
    }

    function streamWrapper(): StreamWrapperBase
    {
        return new MembershipStreamWrapper();
    }

    /**
     * Downloads a file from the membership scheme.
     *
     * @param string $applicationID
     *   The application ID.
     * @param string $filename
     *   The filename.
     *
     * @return Response
     */
    public function downloadMembershipFile(string $applicationID, string $filename): Response
    {
        $uri = $applicationID . '/' . $filename;

        return $this->download($uri);
    }
}
