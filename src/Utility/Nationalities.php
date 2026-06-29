<?php

namespace Drupal\esn_membership_manager\Utility;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Exception;

class Nationalities
{
    protected ModuleHandlerInterface $moduleHandler;
    protected array $nationalities = [];
    protected array $nationalitiesISO = [];

    public function __construct(
        ModuleHandlerInterface $moduleHandler,
    )
    {
        $this->moduleHandler = $moduleHandler;
    }

    public function get(bool $getISO = false): array
    {
        if ($getISO) {
            if (!empty($this->nationalitiesISO)) {
                return $this->nationalitiesISO;
            }
        } else {
            if (!empty($this->nationalities)) {
                return $this->nationalities;
            }
        }

        try {
            $path = $this->moduleHandler->getModule('esn_membership_manager')->getPath() . '/assets/data/nationalities.csv';
        } catch (Exception) {
            $this->nationalities = [];
            $this->nationalitiesISO = [];
            return [];
        }

        $nationalities = [];

        if (file_exists($path)) {
            if (($handle = fopen($path, "r")) !== FALSE) {
                /** @noinspection PhpRedundantOptionalArgumentInspection */
                while (($data = fgetcsv($handle, 1000, ",", "\"", "\\")) !== FALSE) {
                    if ($getISO) {
                        if (empty($data[0]) || empty($data[1])) continue;
                        $nationalities[trim($data[0])] = trim($data[1]);
                    } else {
                        if (empty($data[1])) continue;
                        $val = trim($data[1]);
                        $nationalities[$val] = $val;
                    }
                }
                fclose($handle);
            }
        }

        if ($getISO) {
            $this->nationalitiesISO = $nationalities;
        } else {
            $this->nationalities = $nationalities;
        }
        return $nationalities;
    }
}