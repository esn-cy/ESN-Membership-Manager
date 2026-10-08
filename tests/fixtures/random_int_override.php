<?php

namespace Drupal\esn_membership_manager\Controller;


use Exception;
use Random\RandomException;

if (!function_exists('Drupal\esn_membership_manager\Controller\random_int')) {
    /**
     * @throws RandomException
     * @throws Exception
     */
    function random_int(int $min, int $max): int
    {
        if (!empty($GLOBALS['fail_random_int'])) {
            throw new Exception('Entropy source failure');
        }
        return \random_int($min, $max);
    }
}
