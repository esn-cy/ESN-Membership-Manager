<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Page;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ScanPage extends ControllerBase
{
    protected Extension $module;

    public function __construct(
        ModuleHandlerInterface $moduleHandler,
    )
    {
        $this->module = $moduleHandler->getModule('esn_membership_manager');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ModuleHandlerInterface $moduleHandler */
        $moduleHandler = $container->get('module_handler');

        return new static(
            $moduleHandler,
        );
    }

    public function scanPage(): array
    {
        $filePath = $this->module->getPath() . '/html/scanElement.html';

        $htmlContent = file_exists($filePath)
            ? file_get_contents($filePath)
            : '<p>Error: Could not load the content file.</p>';

        return [
            '#type' => 'processed_text',
            '#text' => $htmlContent,
            '#format' => 'full_html',
        ];
    }
}