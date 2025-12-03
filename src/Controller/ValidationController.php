<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\webform\Entity\WebformSubmission;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class ValidationController extends ControllerBase
{
    protected $configFactory;

    public function __construct(ConfigFactoryInterface $configFactory)
    {
        $this->configFactory = $configFactory;
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        return new static(
            $configFactory
        );
    }

    public function validateToken($user_token): JsonResponse
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        $query = Drupal::entityQuery('webform_submission')
            ->accessCheck(FALSE)
            ->condition('webform_id', $moduleConfig->get('webform_id'));
        $sids = $query->execute();

        if (empty($sids)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Token not found.']);
        }

        $found_submission = null;
        foreach ($sids as $sid) {
            $submission = WebformSubmission::load($sid);
            $data = $submission->getData();
            if (!empty($data['user_token']) && $data['user_token'] === $user_token) {
                $found_submission = $submission;
                break;
            }
        }

        if (!$found_submission) {
            return new JsonResponse(['status' => 'error', 'message' => 'Token not found.']);
        }

        $data = $found_submission->getData();
        $pass_is_enabled = $data['pass_is_enabled'] ?? 0;
        $last_scan_str = $data['last_scan_date'] ?? null;
        $now = new DrupalDateTime();

        if ($pass_is_enabled >= 1) {
            if ($last_scan_str) {
                $last_scan = new DrupalDateTime($last_scan_str);
                $diff = $now->getTimestamp() - $last_scan->getTimestamp();

                if ($diff >= 86400) {
                    $data['last_scan_date'] = $now->format('Y-m-d H:i:s');
                    $data['pass_is_enabled'] = 2;
                    $found_submission->setData($data);
                    $found_submission->save();

                    return new JsonResponse(['status' => 'approved', 'message' => '✅Approve - ' . $data['occupation']]);
                } else {
                    return new JsonResponse(['status' => 'already_scanned', 'message' => '⚠️Already scanned within 24h - ' . $data['occupation']]);
                }
            } else {
                $data['last_scan_date'] = $now->format('Y-m-d H:i:s');

                $data['pass_is_enabled'] = 2;
                $found_submission->setData($data);
                $found_submission->setData($data);
                $found_submission->save();

                return new JsonResponse(['status' => 'approved', 'message' => '✅Approve']);
            }
        } else {
            $approval_status = $data['approval_status'] ?? 'Unknown';
            return new JsonResponse([
                'status' => 'declined',
                'message' => '❌Declined (Approval status: ' . $approval_status . ')',
            ]);
        }
    }
}

