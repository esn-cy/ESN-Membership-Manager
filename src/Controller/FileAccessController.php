<?php

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Handles file downloads for the 'membership://' scheme.
 */
class FileAccessController extends ControllerBase
{
    protected $moduleHandler;
    protected LoggerChannelInterface $logger;

    public function __construct(
        ModuleHandlerInterface        $moduleHandler,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        $this->moduleHandler = $moduleHandler;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ModuleHandlerInterface $moduleHandler */
        $moduleHandler = $container->get('module_handler');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $moduleHandler,
            $loggerFactory
        );
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
    public function download(string $applicationID, string $filename): Response
    {
        $uri = 'membership://' . $applicationID . '/' . $filename;

        if (!file_exists($uri)) {
            $realPath = realpath(dirname(DRUPAL_ROOT) . '/../../private/esn_membership_manager_storage/' . $applicationID . '/' . $filename);
            $directoryPath = dirname(DRUPAL_ROOT) . '/../../private/esn_membership_manager_storage';

            $this->logger->warning('FileAccessController: File not found: @uri. Debug Info: Realpath: @real. Dir exists: @dir_exists. Dir writable: @dir_writable. Root: @root', [
                '@uri' => $uri,
                '@real' => $realPath ?: 'FALSE',
                '@dir_exists' => is_dir($directoryPath) ? 'YES' : 'NO',
                '@dir_writable' => is_writable($directoryPath) ? 'YES' : 'NO',
                '@root' => DRUPAL_ROOT
            ]);
            throw new NotFoundHttpException();
        }

        $headers = $this->moduleHandler->invokeAll('file_download', [$uri]);

        if (empty($headers) || (isset($headers[0]) && $headers[0] === -1)) {
            foreach ($headers as $header) {
                if ($header === -1) {
                    throw new AccessDeniedHttpException();
                }
            }

            if (empty($headers)) {
                $this->logger->debug('FileAccessController: Access denied (no headers returned) for @uri', ['@uri' => $uri]);
                throw new AccessDeniedHttpException();
            }
        }

        return new BinaryFileResponse($uri, 200, $headers);
    }
}
