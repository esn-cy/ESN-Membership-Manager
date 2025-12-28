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
        LoggerChannelFactoryInterface $logger_factory
    )
    {
        $this->moduleHandler = $moduleHandler;
        $this->logger = $logger_factory->get('esn_membership_manager');
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
     * @param int $application_id
     *   The application ID.
     * @param string $filename
     *   The filename.
     *
     * @return Response
     */
    public function download(int $application_id, string $filename): Response
    {
        $uri = 'membership://' . $application_id . '/' . $filename;

        if (!file_exists($uri)) {
            $real_path = realpath(dirname(DRUPAL_ROOT) . '/../../private/esn_membership_manager_storage/' . $application_id . '/' . $filename);
            $dir_path = dirname(DRUPAL_ROOT) . '/../../private/esn_membership_manager_storage';

            $this->logger->warning('FileAccessController: File not found: @uri. Debug Info: Realpath: @real. Dir exists: @dir_exists. Dir writable: @dir_writable. Root: @root', [
                '@uri' => $uri,
                '@real' => $real_path ?: 'FALSE',
                '@dir_exists' => is_dir($dir_path) ? 'YES' : 'NO',
                '@dir_writable' => is_writable($dir_path) ? 'YES' : 'NO',
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
