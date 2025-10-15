<?php

namespace Drupal\esn_membership_manager\Page;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ScanPage extends ControllerBase
{
    protected $moduleHandler;

    public function __construct(ModuleHandlerInterface $module_handler)
    {
        $this->moduleHandler = $module_handler;
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ModuleHandlerInterface $module_handler */
        $module_handler = $container->get('module_handler');

        return new static(
            $module_handler
        );
    }

    public function scanPage(): array
    {
        $module_path = $this->moduleHandler->getModule('esn_membership_manager')->getPath();
        $file_path = $module_path . '/html/scanElement.html';

        $html_content = file_exists($file_path)
            ? file_get_contents($file_path)
            : '<p>Error: Could not load the content file.</p>';

        return [
            '#type' => 'processed_text',
            '#text' => $html_content,
            '#format' => 'full_html',
        ];
    }
}