<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Service;


use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\esn_membership_manager\Cron\GDPRCron;

class CronService
{
    protected GDPRCron $gdprCron;

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function __construct(
        EntityTypeManagerInterface    $entityTypeManager,
        FileService                   $fileService,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        $this->gdprCron = new GDPRCron($entityTypeManager, $fileService, $loggerFactory);
    }

    function execute(): void
    {
        $this->gdprCron->execute();
    }
}